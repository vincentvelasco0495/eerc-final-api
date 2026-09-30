<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsMedia extends Model
{
    protected $table = 'cms_media';

    protected $fillable = [
        'public_id',
        'uploaded_by',
        'disk',
        'path',
        'url',
        'filename',
        'original_name',
        'mime',
        'size_bytes',
        'alt',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
