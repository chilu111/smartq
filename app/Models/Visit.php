<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    protected $fillable = [
        'patient_id', 'ticket_number', 'idempotency_key', 'status', 'registration_complete', 'created_by', 'completed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'registration_complete' => 'boolean',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(QueueEvent::class);
    }
}
