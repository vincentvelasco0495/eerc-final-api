<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseLibraryFile extends Model
{
    protected $fillable = [
        'public_id',
        'folder_id',
        'original_name',
        'storage_path',
        'mime',
        'size_bytes',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(CourseLibraryFolder::class, 'folder_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
