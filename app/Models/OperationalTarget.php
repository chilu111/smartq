<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalTarget extends Model
{
    protected $fillable = ['department_id', 'target_wait_minutes', 'target_service_minutes'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
