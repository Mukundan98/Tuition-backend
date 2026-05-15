<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fee extends Model
{
    protected $fillable = [
        'student_id',
        'title',
        'notes',
        'amount',
        'due_date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FeePayment::class);
    }

    /** @param  mixed  $dueDate  Carbon|string|null */
    public static function statusFor(float|string $amount, float|string $paidTotal, mixed $dueDate): string
    {
        $balance = (float) $amount - (float) $paidTotal;
        if ($balance <= 0.009) {
            return 'paid';
        }
        $paid = (float) $paidTotal;
        if ($paid > 0) {
            return 'partial';
        }
        if ($dueDate !== null && \Carbon\Carbon::parse($dueDate)->lt(\Carbon\Carbon::today())) {
            return 'overdue';
        }

        return 'pending';
    }
}
