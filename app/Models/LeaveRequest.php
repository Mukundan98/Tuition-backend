<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    protected $fillable = [
        'user_id',
        'leave_type',
        'from_date',
        'to_date',
        'reason',
        'attachment_path',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
