<?php

namespace App\Models;

use App\Services\GradeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamResult extends Model
{
    protected $fillable = [
        'exam_id',
        'student_id',
        'subject_id',
        'marks_obtained',
        'grade',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'marks_obtained' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ExamResult $r): void {
            $examId = $r->exam_id ?: $r->exam?->id;
            if ($examId === null) {
                return;
            }
            $exam = $r->relationLoaded('exam') ? $r->exam : Exam::query()->find($examId);
            if (! $exam instanceof Exam) {
                return;
            }
            $r->grade = GradeService::fromMarks((float) $r->marks_obtained, (float) $exam->max_marks);
        });
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
