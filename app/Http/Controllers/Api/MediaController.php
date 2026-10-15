<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Small, reusable upload endpoint. Used by the quiz-question builder to
 * attach supporting materials (image/video/PDF) to a question or to an
 * activity's instructions, and to attach an image to an individual
 * Multiple Choice option — both just need "give me a URL back", not a
 * dedicated form-request per feature.
 *
 * POST /api/teacher/media/upload  (multipart/form-data, field: file)
 * → { path, url, type: 'image'|'video'|'pdf', duration?: float }
 *
 * Requires: composer require james-heinrich/getid3
 * (used only to read a video's duration server-side — never trust a
 * client-reported duration for validation).
 */
class MediaController extends Controller
{
    private const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp'];
    private const VIDEO_EXTS = ['mp4', 'mov', 'webm', 'm4v'];

    private const MAX_IMAGE_KB      = 10240;  // 10 MB
    private const MAX_VIDEO_KB      = 153600; // 150 MB — generous ceiling; duration is the real gate
    private const MAX_VIDEO_SECONDS = 300;    // 5 minutes

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:' . implode(',', [...self::IMAGE_EXTS, 'pdf', ...self::VIDEO_EXTS]),
                'max:' . self::MAX_VIDEO_KB,
            ],
        ]);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());
        $type = match (true) {
            $ext === 'pdf'                          => 'pdf',
            in_array($ext, self::VIDEO_EXTS, true)  => 'video',
            default                                  => 'image',
        };

        if ($type === 'image' && $file->getSize() > self::MAX_IMAGE_KB * 1024) {
            return response()->json([
                'message' => 'Images must be 10MB or smaller.',
            ], 422);
        }

        $duration = null;

        if ($type === 'video') {
            $duration = $this->videoDurationSeconds($file->getRealPath());

            if ($duration === null) {
                return response()->json([
                    'message' => 'Could not read that video\'s length — please try a different file.',
                ], 422);
            }

            if ($duration > self::MAX_VIDEO_SECONDS) {
                return response()->json([
                    'message' => 'Videos must be 5 minutes or shorter. This one is ' .
                        gmdate('i:s', (int) round($duration)) . ' long.',
                ], 422);
            }
        }

        $path = $file->store('question-attachments', 'public');

        return response()->json([
            'path'     => $path,
            'url'      => Storage::disk('public')->url($path),
            'type'     => $type,
            'duration' => $duration,
        ], 201);
    }

    /**
     * Reads a video's duration in seconds using getID3 (pure PHP, no
     * system binaries required). Returns null if it can't be determined,
     * which the caller treats as a rejected upload rather than a bypass.
     */
    private function videoDurationSeconds(string $path): ?float
    {
        if (! class_exists(\getID3::class)) {
            return null;
        }

        $getID3 = new \getID3();
        $info   = $getID3->analyze($path);

        return isset($info['playtime_seconds']) ? (float) $info['playtime_seconds'] : null;
    }
}