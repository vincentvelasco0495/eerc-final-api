<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminUpload extends Model
{
    protected $fillable = ['public_id', 'user_id', 'title', 'asset_type', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
