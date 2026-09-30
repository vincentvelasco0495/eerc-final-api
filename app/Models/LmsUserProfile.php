<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsUserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'program_id',
        'streak_days',
        'watermark_name',
        'session_warning',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'session_warning' => 'boolean',
            'joined_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }
}
