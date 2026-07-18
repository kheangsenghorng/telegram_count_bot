<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Events\TelegramGroupStatusUpdated;
use App\Models\SubscriptionUsageLog;
use App\Models\TelegramGroup;
use App\Models\UserSubscription;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class GroupHandler
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    public function connect(
        array $chat,
        array $from,
        string $text
    ): JsonResponse {
        $chatId = (string) ($chat['id'] ?? '');
        $chatTitle = $chat['title'] ?? 'Unknown Group';
        $chatType = $chat['type'] ?? 'private';

        /*
        |--------------------------------------------------------------------------
        | Only groups and supergroups
        |--------------------------------------------------------------------------
        */
        if (! in_array(
            $chatType,
            ['group', 'supergroup'],
            true
        )) {
            $this->telegram->sendMessage(
                $chatId,
                '❌ Please use /connect inside a Telegram group.'
            );

            return response()->json([
                'ok' => true,
            ]);
        }

        $telegramId = (string) ($from['id'] ?? '');

        $parts = preg_split(
            '/\s+/',
            trim($text)
        );

        $subscriptionKey = $parts[1] ?? null;

        /*
        |--------------------------------------------------------------------------
        | Find subscription
        |--------------------------------------------------------------------------
        */
        if ($subscriptionKey) {
            $subscription = UserSubscription::with('package')
                ->where(
                    'subscription_key',
                    $subscriptionKey
                )
                ->where('status', 'active')
                ->first();
        } else {
            $subscription = UserSubscription::with([
                'package',
                'user',
            ])
                ->where('status', 'active')
                ->whereHas(
                    'user',
                    fn ($query) => $query->where(
                        'telegram_id',
                        $telegramId
                    )
                )
                ->latest()
                ->first();
        }

        /*
        |--------------------------------------------------------------------------
        | Subscription does not exist
        |--------------------------------------------------------------------------
        */
        if (! $subscription) {
            $this->telegram->sendMessage(
                $chatId,
                '❌ No active subscription found. '
                . 'Provide a key: /connect YOUR_KEY'
            );

            return response()->json([
                'ok' => true,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Subscription expired
        |--------------------------------------------------------------------------
        */
        if (
            $subscription->ends_at
            && now()->greaterThan($subscription->ends_at)
        ) {
            $this->telegram->sendMessage(
                $chatId,
                '❌ Subscription expired.'
            );

            return response()->json([
                'ok' => true,
            ]);
        }

        $groupLimit = (int) (
            $subscription->override_group_limit
            ?? $subscription->package?->group_limit
            ?? 0
        );

        $currentGroupUsed = (int) (
            $subscription->group_used ?? 0
        );

        /*
        |--------------------------------------------------------------------------
        | Find existing Telegram group
        |--------------------------------------------------------------------------
        */
        $existing = TelegramGroup::query()
            ->where('group_id', $chatId)
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Already connected
        |--------------------------------------------------------------------------
        |
        | Running /connect is itself activity, so refresh the group's realtime
        | status without consuming another group slot.
        |
        */
        if (
            $existing
            && $existing->status === 'connected'
        ) {
            $existing->update([
                'group_name' => $chatTitle,
                'group_type' => $chatType,

                'connection_status' => 'online',
                'activity_status' => 'active',

                'last_activity_at' => now(),
                'last_heartbeat_at' => now(),
            ]);

            $this->broadcastGroupStatus(
                $existing->fresh()
            );

            $this->telegram->sendMessage(
                $chatId,
                '✅ This group is already connected.'
            );

            return response()->json([
                'ok' => true,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Check group limit
        |--------------------------------------------------------------------------
        */
        if (
            $groupLimit > 0
            && $currentGroupUsed >= $groupLimit
        ) {
            $this->telegram->sendMessage(
                $chatId,
                "❌ Group limit reached.\n\n"
                . "Your package allows {$groupLimit} groups.\n"
                . "Used groups: {$currentGroupUsed}/{$groupLimit}\n\n"
                . 'Please upgrade package or disconnect another group first.'
            );

            return response()->json([
                'ok' => true,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Connect / reconnect group
        |--------------------------------------------------------------------------
        */
        $group = DB::transaction(
            function () use (
                $existing,
                $subscription,
                $chatId,
                $chatTitle,
                $chatType
            ): TelegramGroup {
                $now = now();

                /*
                |--------------------------------------------------------------------------
                | Reconnect existing group
                |--------------------------------------------------------------------------
                */
                if ($existing) {
                    $existing->update([
                        'user_id' =>
                            $subscription->user_id,

                        'subscription_id' =>
                            $subscription->userSubscriptionsID,

                        'group_name' =>
                            $chatTitle,

                        'group_type' =>
                            $chatType,

                        'status' =>
                            'connected',

                        'connection_status' =>
                            'online',

                        'activity_status' =>
                            'active',

                        'connected_at' =>
                            $now,

                        'last_activity_at' =>
                            $now,

                        'last_heartbeat_at' =>
                            $now,
                    ]);

                    $group = $existing;
                    $action = 'reconnected';
                } else {
                    /*
                    |--------------------------------------------------------------------------
                    | Create new group
                    |--------------------------------------------------------------------------
                    */
                    $group = TelegramGroup::create([
                        'user_id' =>
                            $subscription->user_id,

                        'subscription_id' =>
                            $subscription->userSubscriptionsID,

                        'group_id' =>
                            $chatId,

                        'group_name' =>
                            $chatTitle,

                        'group_type' =>
                            $chatType,

                        'bot_added_at' =>
                            $now,

                        'connected_at' =>
                            $now,

                        'status' =>
                            'connected',

                        'connection_status' =>
                            'online',

                        'activity_status' =>
                            'active',

                        'last_activity_at' =>
                            $now,

                        'last_heartbeat_at' =>
                            $now,
                    ]);

                    $action = 'connected';
                }

                /*
                |--------------------------------------------------------------------------
                | Increase subscription group usage
                |--------------------------------------------------------------------------
                */
                UserSubscription::query()
                    ->where(
                        'userSubscriptionsID',
                        $subscription->userSubscriptionsID
                    )
                    ->increment('group_used');

                /*
                |--------------------------------------------------------------------------
                | Usage log
                |--------------------------------------------------------------------------
                */
                SubscriptionUsageLog::create([
                    'subscription_id' =>
                        $subscription->userSubscriptionsID,

                    'user_id' =>
                        $subscription->user_id,

                    'type' =>
                        'group',

                    'action' =>
                        $action,

                    'value' =>
                        1,

                    'description' =>
                        $action === 'reconnected'
                            ? 'Telegram group reconnected'
                            : 'Telegram group connected',

                    'metadata' => [
                        'group_id' =>
                            $chatId,

                        'group_name' =>
                            $chatTitle,

                        'group_type' =>
                            $chatType,
                    ],
                ]);

                return $group;
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Broadcast after database transaction succeeds
        |--------------------------------------------------------------------------
        */
        $this->broadcastGroupStatus(
            $group->fresh()
        );

        /*
        |--------------------------------------------------------------------------
        | Telegram success message
        |--------------------------------------------------------------------------
        */
        $this->telegram->sendMessage(
            $chatId,
            "✅ Group connected successfully!\n"
            . "Group: {$chatTitle}"
        );

        return response()->json([
            'ok' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Broadcast group status + current system counts
    |--------------------------------------------------------------------------
    */
    private function broadcastGroupStatus(
        TelegramGroup $group
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Only count groups that are currently connected to the system.
        |
        | This prevents historical/disconnected groups from appearing as
        | system offline groups.
        |--------------------------------------------------------------------------
        */
        $baseQuery = TelegramGroup::query()
            ->where('status', 'connected');

        $totalGroups = (clone $baseQuery)
            ->count();

        $onlineGroups = (clone $baseQuery)
            ->where(
                'connection_status',
                'online'
            )
            ->count();

        $offlineGroups = (clone $baseQuery)
            ->where(
                'connection_status',
                'offline'
            )
            ->count();

        $activeGroups = (clone $baseQuery)
            ->where(
                'activity_status',
                'active'
            )
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Reverb realtime broadcast
        |--------------------------------------------------------------------------
        */
        TelegramGroupStatusUpdated::dispatch(
            groupId: (string) $group->group_id,

            groupName:
                $group->group_name
                ?? 'Unknown Group',

            connectionStatus:
                $group->connection_status
                ?? 'offline',

            activityStatus:
                $group->activity_status
                ?? 'inactive',

            lastActivityAt:
                $group->last_activity_at
                    ?->toIso8601String(),

            totalGroups:
                $totalGroups,

            onlineGroups:
                $onlineGroups,

            offlineGroups:
                $offlineGroups,

            activeGroups:
                $activeGroups,
        );
    }
}