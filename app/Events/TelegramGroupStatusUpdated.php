<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class TelegramGroupStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $groupId,
        public readonly string $groupName,
        public readonly string $connectionStatus,
        public readonly string $activityStatus,
        public readonly ?string $lastActivityAt,

        // System summary
        public readonly int $totalGroups,
        public readonly int $onlineGroups,
        public readonly int $offlineGroups,
        public readonly int $activeGroups,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('telegram.groups'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'telegram.group.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Changed group
            |--------------------------------------------------------------------------
            */
            'group' => [
                'group_id' => $this->groupId,
                'group_name' => $this->groupName,
                'connection_status' => $this->connectionStatus,
                'activity_status' => $this->activityStatus,
                'last_activity_at' => $this->lastActivityAt,
            ],

            /*
            |--------------------------------------------------------------------------
            | System group summary
            |--------------------------------------------------------------------------
            */
            'summary' => [
                'total' => $this->totalGroups,
                'online' => $this->onlineGroups,
                'offline' => $this->offlineGroups,
                'active' => $this->activeGroups,
            ],

            'updated_at' => now()->toIso8601String(),
        ];
    }
}