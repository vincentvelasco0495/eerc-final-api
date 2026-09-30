<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaderboardEntry extends Model
{
    protected $fillable = [
        'period',
        'rank_order',
        'user_id',
        'display_name',
        'program_code',
        'score',
        'badge_key',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
