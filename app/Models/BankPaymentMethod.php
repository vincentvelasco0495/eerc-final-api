<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankPaymentMethod extends Model
{
    protected $fillable = [
        'public_id',
        'account_name',
        'bank_name',
        'account_number',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
