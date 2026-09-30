<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Instructor extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'achievements',
        'display_name',
        'position',
        'bio',
        'facebook',
        'linkedin',
        'twitter',
        'instagram',
        'profile_path',
        'cover_path',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
