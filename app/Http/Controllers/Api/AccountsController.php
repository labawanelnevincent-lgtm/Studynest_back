<?php
// app/Http/Controllers/Api/AccountsController.php
namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Response;

class AccountsController extends BaseApiController
{
    /**
     * Get all student and teacher accounts with credentials
     * Admin only endpoint
     */
    public function index()
    {
        try {
            // Get all students and teachers with their details
            $students = User::where('role', 'student')
                ->with('studentProfile')
                ->select('id', 'name', 'email', 'username', 'role', 'is_first_login', 'created_at', 'updated_at')
                ->orderBy('name')
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'username' => $user->username,
                        'role' => $user->role,
                        'is_first_login' => $user->is_first_login,
                        'student_number' => $user->studentProfile?->student_number,
                        'grade' => $user->studentProfile?->grade,
                        'section' => $user->studentProfile?->section,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ];
                });

            $teachers = User::where('role', 'teacher')
                ->select('id', 'name', 'email', 'username', 'role', 'is_first_login', 'created_at', 'updated_at')
                ->orderBy('name')
                ->get();

            return $this->respondWithData([
                'students' => $students,
                'teachers' => $teachers,
                'total_students' => $students->count(),
                'total_teachers' => $teachers->count(),
            ]);
        } catch (\Exception $e) {
            return $this->respondWithError('Failed to fetch accounts: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reset a user's password (admin action)
     * Generates a temporary password and sets is_first_login to true
     */
    public function resetPassword($userId)
    {
        try {
            $user = User::findOrFail($userId);
            
            // Generate random temporary password
            $tempPassword = 'TempPass' . rand(100000, 999999) . '!';
            
            $user->update([
                'password' => bcrypt($tempPassword),
                'is_first_login' => true, // Force password change on next login
            ]);

            return $this->respondWithData([
                'message' => 'Password reset successful',
                'temporary_password' => $tempPassword,
                'user_id' => $user->id,
            ]);
        } catch (\Exception $e) {
            return $this->respondWithError('Failed to reset password: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
