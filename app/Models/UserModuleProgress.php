<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserModuleProgress extends Model
{
    protected $table = 'user_module_progress';

    protected $fillable = ['user_id', 'module_id', 'progress_percent', 'last_position_label'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_id');
    }
}
