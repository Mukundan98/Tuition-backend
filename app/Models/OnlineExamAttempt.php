<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineExamAttempt extends Model
{
    protected $fillable = [
        'online_exam_id',
        'student_id',
        'started_at',
        'submitted_at',
        'responses',
        'score',
        'max_score',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'responses' => 'array',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
        ];
    }

    public function onlineExam(): BelongsTo
    {
        return $this->belongsTo(OnlineExam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
