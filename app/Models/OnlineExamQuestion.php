<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineExamQuestion extends Model
{
    protected $fillable = [
        'online_exam_id',
        'sort_order',
        'prompt',
        'options',
        'correct_index',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'sort_order' => 'integer',
            'correct_index' => 'integer',
            'points' => 'decimal:2',
        ];
    }

    public function onlineExam(): BelongsTo
    {
        return $this->belongsTo(OnlineExam::class);
    }
}
