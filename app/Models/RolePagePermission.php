<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePagePermission extends Model
{
    protected $fillable = [
        'role',
        'path',
        'match_type',
        'query',
        'label',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'query' => 'array',
            'sort_order' => 'integer',
        ];
    }
}
