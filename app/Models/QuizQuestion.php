<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
 
class QuizQuestion extends Model
{
    use HasFactory;
 
    protected $fillable = [
        'activity_id',
        'order',
        'question',
        'answer_type',     // Multiple Choice | Text | Photo
        'checking_mode',   // Auto | Manual
        'choices',
        'correct_answer',
        'points',
        'attachment_path', // supporting material: image or PDF
        'attachment_type', // 'image' | 'pdf'
    ];
 
    protected $casts = [
        'choices' => 'array',
        'order'   => 'integer',
        'points'  => 'integer',
    ];
 
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SubmissionAnswer::class);
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->attachment_path ? Storage::disk('public')->url($this->attachment_path) : null;
    }
}