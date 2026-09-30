<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactPageSection extends Model
{
    protected $fillable = [
        'section_key',
        'title',
        'content_json',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'sort_order' => 'integer',
        ];
    }
}
