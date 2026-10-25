<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseLibraryFolder extends Model
{
    protected $fillable = [
        'public_id',
        'tab_id',
        'parent_id',
        'name',
        'sort_order',
    ];

    public function tab(): BelongsTo
    {
        return $this->belongsTo(CourseLibraryTab::class, 'tab_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CourseLibraryFile::class, 'folder_id')->orderBy('original_name');
    }
}
