<?php

namespace App\Services;

use App\Models\QueueEntry;
use App\Models\Visit;
use Carbon\CarbonInterface;

class QueueTimingService
{
    public function waitingSeconds(QueueEntry $entry): ?int
    {
        if (! $entry->first_called_at || ! $entry->queued_at) {
            return null;
        }

        return max(0, $entry->queued_at->diffInSeconds($entry->first_called_at) - $entry->waiting_paused_seconds);
    }

    public function elapsedWaitingSeconds(QueueEntry $entry, ?CarbonInterface $now = null): ?int
    {
        if (! $entry->queued_at || $entry->first_called_at) {
            return null;
        }

        $currentHold = $entry->held_at && ! $entry->first_called_at ? $entry->held_at->diffInSeconds($now ?? now()) : 0;

        return max(0, $entry->queued_at->diffInSeconds($now ?? now()) - $entry->waiting_paused_seconds - $currentHold);
    }

    public function serviceSeconds(QueueEntry $entry): ?int
    {
        if (! $entry->service_started_at || ! $entry->service_completed_at) {
            return null;
        }

        return max(0, $entry->service_started_at->diffInSeconds($entry->service_completed_at) - $entry->service_paused_seconds);
    }

    public function visitSeconds(Visit $visit): ?int
    {
        if (! $visit->completed_at) {
            return null;
        }

        return max(0, $visit->created_at->diffInSeconds($visit->completed_at));
    }
}
