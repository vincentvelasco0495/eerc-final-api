<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseLibraryTab extends Model
{
    protected $fillable = [
        'public_id',
        'course_id',
        'title',
        'slug',
        'sort_order',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(CourseLibraryFolder::class, 'tab_id')->orderBy('sort_order')->orderBy('id');
    }
}
