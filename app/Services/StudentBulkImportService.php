<?php

namespace App\Services;

use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Models\AdminSetting;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;

/**
 * Parses a .docx bulk-upload file (see the downloadable template) and
 * validates every row before any accounts are created.
 *
 * Usage:
 *   $service  = new StudentBulkImportService();
 *   $records  = $service->parse($absolutePathToDocx);
 *   $result   = $service->validateRecords($records); // ['valid' => [...], 'invalid' => [...]]
 */
class StudentBulkImportService
{
    /** Normalized header text → internal field key. Add synonyms here if needed. */
    private const HEADER_MAP = [
        'student number'  => 'student_number',
        'student no'      => 'student_number',
        'student no.'     => 'student_number',
        'student id'      => 'student_number',
        'first name'      => 'first_name',
        'middle name'     => 'middle_name',
        'last name'       => 'last_name',
        'suffix'          => 'suffix',
        'grade'           => 'grade',
        'grade level'     => 'grade',
        'section'         => 'section',
        'email'           => 'email',
        'email address'   => 'email',
    ];

    private const REQUIRED_FIELDS = ['student_number', 'first_name', 'last_name', 'grade', 'email'];

    /**
     * Reads the first table found in the .docx and returns an array of
     * associative rows keyed by field name (student_number, first_name, ...).
     * The first table row is always treated as the header row.
     *
     * @throws \RuntimeException if no table / no header row can be found
     */
    public function parse(string $absolutePath): array
    {
        $phpWord = IOFactory::load($absolutePath);

        $rawRows = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof Table) {
                    foreach ($element->getRows() as $row) {
                        $cells = [];
                        foreach ($row->getCells() as $cell) {
                            $cells[] = $this->extractCellText($cell);
                        }
                        $rawRows[] = $cells;
                    }
                    // Only read the first table in the document.
                    break 2;
                }
            }
        }

        if (empty($rawRows)) {
            throw new \RuntimeException('No table was found in the uploaded document. Please use the provided template.');
        }

        $headerRow = array_map(fn ($h) => trim(strtolower($h)), array_shift($rawRows));
        $columnMap = [];
        foreach ($headerRow as $i => $header) {
            $columnMap[$i] = self::HEADER_MAP[$header] ?? null;
        }

        if (! in_array('student_number', $columnMap, true) && ! in_array('first_name', $columnMap, true)) {
            throw new \RuntimeException('The table headers do not match the expected template. Please download and use the provided template.');
        }

        $records = [];
        foreach ($rawRows as $rowIndex => $cells) {
            $isBlank = count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0;
            if ($isBlank) {
                continue;
            }

            $record = [
                'student_number' => null, 'first_name' => null, 'middle_name' => null,
                'last_name' => null, 'suffix' => null, 'grade' => null,
                'section' => null, 'email' => null,
            ];
            foreach ($cells as $i => $value) {
                $key = $columnMap[$i] ?? null;
                if ($key !== null) {
                    $record[$key] = trim((string) $value);
                }
            }
            // Human row number: +1 for the header row, +1 to make it 1-indexed.
            $record['_row'] = $rowIndex + 2;
            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records  Rows returned by parse()
     * @return array{valid: array<int, array<string, mixed>>, invalid: array<int, array<string, mixed>>}
     */
    public function validateRecords(array $records): array
    {
        $valid   = [];
        $invalid = [];
        $autoAssignSection = (bool) AdminSetting::get('auto_section_assignment', false);
        if ($autoAssignSection) {
            $sectionsByGrade = Section::query()
                ->where('is_active', true)
                ->withCount(['students as active_students_count' => fn ($query) => $query->where('status', 'Active')])
                ->get()
                ->groupBy('grade_level');
            $sectionLoads = [];
            foreach ($sectionsByGrade as $gradeSections) {
                foreach ($gradeSections as $gradeSection) {
                    $sectionLoads[$gradeSection->id] = $gradeSection->active_students_count;
                }
            }
        }

        // Track duplicates *within this file* so two rows can't both "win".
        $seenNumbers = [];
        $seenEmails  = [];

        foreach ($records as $record) {
            $errors = [];

            $requiredFields = self::REQUIRED_FIELDS;
            if (!$autoAssignSection) {
                $requiredFields[] = 'section';
            }
            foreach ($requiredFields as $field) {
                if (empty($record[$field])) {
                    $errors[] = ucwords(str_replace('_', ' ', $field)) . ' is required.';
                }
            }

            if (! empty($record['student_number']) && ! preg_match('/^[A-Za-z0-9\-]+$/', $record['student_number'])) {
                $errors[] = 'Student Number can only contain letters, numbers, and hyphens.';
            }

            if (! empty($record['email']) && ! filter_var($record['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format.';
            }

            $section = null;
            if ($autoAssignSection && !empty($record['grade'])) {
                if (empty($sectionsByGrade[$record['grade']])) {
                    $errors[] = "No active section is available for grade \"{$record['grade']}\".";
                }
            } elseif (!empty($record['grade']) && !empty($record['section'])) {
                $section = Section::where('name', $record['section'])
                    ->where('grade_level', $record['grade'])
                    ->first();
                if (! $section) {
                    $errors[] = "Section \"{$record['section']}\" was not found for grade \"{$record['grade']}\".";
                }
            }

            $studentNumber = strtoupper(trim((string) ($record['student_number'] ?? '')));
            $email         = strtolower(trim((string) ($record['email'] ?? '')));

            if ($studentNumber !== '') {
                if (isset($seenNumbers[$studentNumber])) {
                    $errors[] = 'Duplicate Student Number within this file.';
                } elseif (Student::where('student_number', $studentNumber)->exists()) {
                    $errors[] = 'This Student Number already exists in the system.';
                }
            }

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (isset($seenEmails[$email])) {
                    $errors[] = 'Duplicate Email within this file.';
                } elseif (
                    Student::whereRaw('LOWER(email) = ?', [$email])->exists()
                    || User::whereRaw('LOWER(email) = ?', [$email])->exists()
                ) {
                    $errors[] = 'This Email already exists in the system.';
                }
            }

            if (! empty($errors)) {
                $invalid[] = array_merge($record, ['errors' => $errors]);
                continue;
            }

            $seenNumbers[$studentNumber] = true;
            $seenEmails[$email]          = true;

            if ($autoAssignSection) {
                $section = $sectionsByGrade[$record['grade']]
                    ->sort(function (Section $left, Section $right) use ($sectionLoads): int {
                        $loadDifference = $sectionLoads[$left->id] <=> $sectionLoads[$right->id];
                        return $loadDifference !== 0 ? $loadDifference : strcasecmp($left->name, $right->name);
                    })
                    ->first();
                $sectionLoads[$section->id]++;
            }

            $valid[] = array_merge($record, [
                'student_number' => $studentNumber,
                'email'          => $email,
                'section'        => $section->name,
                'section_id'     => $section->id,
            ]);
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    private function extractCellText($cell): string
    {
        $text = '';
        foreach ($cell->getElements() as $el) {
            if ($el instanceof TextRun) {
                foreach ($el->getElements() as $sub) {
                    if (method_exists($sub, 'getText')) {
                        $text .= $sub->getText();
                    }
                }
            } elseif (method_exists($el, 'getText')) {
                $text .= $el->getText();
            }
        }
        return trim($text);
    }
}