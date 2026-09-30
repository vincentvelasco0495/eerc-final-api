<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    use SoftDeletes;

    protected $fillable = ['public_id', 'code', 'slug', 'title', 'description', 'enrollment_fee', 'status', 'banner_path'];

    protected function casts(): array
    {
        return [
            'enrollment_fee' => 'decimal:2',
        ];
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'program_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'program_id');
    }
}
