<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EwalletPaymentMethod extends Model
{
    protected $fillable = [
        'public_id',
        'mobile_number',
        'account_name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
