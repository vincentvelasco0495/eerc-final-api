<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subject extends Model
{
    protected $fillable = ['name'];

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_subject', 'subject_id', 'course_id');
    }
}
