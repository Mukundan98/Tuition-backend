<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    protected $fillable = [
        'class_id',
        'title',
        'exam_date',
        'max_marks',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'max_marks' => 'decimal:2',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    public function examPapers(): HasMany
    {
        return $this->hasMany(ExamPaper::class);
    }

    public function subjectsForClass(): \Illuminate\Support\Collection
    {
        return Subject::query()
            ->where('class_id', $this->class_id)
            ->orderBy('name')
            ->get();
    }
}
