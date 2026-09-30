<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;

    protected $fillable = [
        'public_uid',
        'name',
        'email',
        'password',
        'role',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function lmsProfile(): HasOne
    {
        return $this->hasOne(LmsUserProfile::class);
    }

    public function badges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    public function moduleProgress(): HasMany
    {
        return $this->hasMany(UserModuleProgress::class);
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function instructorProfile(): HasOne
    {
        return $this->hasOne(Instructor::class);
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function authoredAnnouncements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'author_id');
    }

    public function inAppNotifications(): HasMany
    {
        return $this->hasMany(InAppNotification::class);
    }
}
