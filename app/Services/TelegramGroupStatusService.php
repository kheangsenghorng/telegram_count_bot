<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TelegramGroupStatusUpdated;
use App\Models\TelegramGroup;

final class TelegramGroupStatusService
{
    public function broadcastGroupStatus(
        TelegramGroup $group,
        string $connectionStatus,
        string $activityStatus,
        ?string $lastActivityAt = null,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Update current group status
        |--------------------------------------------------------------------------
        */
        $group->update([
            'connection_status' => $connectionStatus,
            'activity_status' => $activityStatus,
            'last_activity_at' => $lastActivityAt,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Calculate current system counts
        |--------------------------------------------------------------------------
        */
        $totalGroups = TelegramGroup::query()->count();

        $onlineGroups = TelegramGroup::query()
            ->where('connection_status', 'online')
            ->count();

        $offlineGroups = TelegramGroup::query()
            ->where('connection_status', 'offline')
            ->count();

        $activeGroups = TelegramGroup::query()
            ->where('activity_status', 'active')
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Broadcast realtime update
        |--------------------------------------------------------------------------
        */
        TelegramGroupStatusUpdated::dispatch(
            groupId: (string) $group->telegramGroupsID,
            groupName: $group->name ?? 'Unknown Group',
            connectionStatus: $connectionStatus,
            activityStatus: $activityStatus,
            lastActivityAt: $lastActivityAt,
            totalGroups: $totalGroups,
            onlineGroups: $onlineGroups,
            offlineGroups: $offlineGroups,
            activeGroups: $activeGroups,
        );
    }
}