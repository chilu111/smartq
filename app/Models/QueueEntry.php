<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QueueEntry extends Model
{
    public const ACTIVE_STATUSES = ['waiting', 'called', 'serving', 'held', 'absent'];

    protected $fillable = [
        'visit_id', 'department_id', 'service_point_id', 'active_visit_key', 'service_point_active_key',
        'status', 'priority', 'priority_reason', 'priority_set_by', 'priority_set_at', 'queued_at',
        'first_called_at', 'last_called_at', 'service_started_at', 'service_completed_at',
        'held_at', 'held_seconds', 'absent_at', 'completed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'priority_set_at' => 'datetime',
            'queued_at' => 'datetime',
            'first_called_at' => 'datetime',
            'last_called_at' => 'datetime',
            'service_started_at' => 'datetime',
            'service_completed_at' => 'datetime',
            'service_pause_started_at' => 'datetime',
            'held_at' => 'datetime',
            'absent_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function servicePoint(): BelongsTo
    {
        return $this->belongsTo(ServicePoint::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(QueueEvent::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}
