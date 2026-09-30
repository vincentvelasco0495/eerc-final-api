<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactFeedbackSubmission extends Model
{
    protected $table = 'contact_feedback_submissions';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'message',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
