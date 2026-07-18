<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Constants\BotCallback;
use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Events\TelegramGroupStatusUpdated;

class LimitHandler
{
    /*
    |--------------------------------------------------------------------------
    | Cache TTLs (seconds)
    |--------------------------------------------------------------------------
    */
    private const TTL_GROUP        = 300;    // 5 min  — latest connected group
    private const TTL_SUBSCRIPTION = 300;    // 5 min  — active subscription
    private const TTL_PACKAGE      = 3600;   // 1 hour — package rows rarely change
    private const TTL_PAY_COUNT    = 60;     // 1 min  — real payment count
    private const TTL_GROUP_LIST   = 60;     // 1 min  — my-groups list
    private const TTL_SCHEMA       = 86400;  // 1 day  — packages PK column detection
    private const TTL_USER_LOOKUP  = 3600;   // 1 hour — telegram_id → users.uuid

    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Cache key builders (public so other services can invalidate too)
    |--------------------------------------------------------------------------
    */
    public static function latestGroupKey(string $userId): string
    {
        // Per-user — previously a single global key, which made
        // My Limits show the most recently connected group of ANY user.
        return "limits:latest_group:{$userId}";
    }

    public static function subscriptionKey(string $userId): string
    {
        return "limits:sub:{$userId}";
    }

    public static function packageKey(string $packageId): string
    {
        return "limits:pkg:{$packageId}";
    }

    public static function paymentCountKey(string $userId): string
    {
        return "limits:paycount:{$userId}";
    }

    public static function groupListKey(string $userId): string
    {
        return "limits:groups:{$userId}";
    }

    public static function userLookupKey(string $telegramUserId): string
    {
        return "limits:tguser:{$telegramUserId}";
    }

    /**
     * Call this from your payment save handler after a new payment is counted,
     * and from PaymentConfirmationService::activatePackage().
     */
    public static function invalidateForUser(string $userId): void
    {
        Cache::forget(self::subscriptionKey($userId));
        Cache::forget(self::paymentCountKey($userId));
        Cache::forget(self::groupListKey($userId));
        Cache::forget(self::latestGroupKey($userId));
    }

    /**
     * Call this from EVERY admin package write (create/update/delete),
     * alongside PublicPackageController::invalidate() and
     * PackageHandler::invalidatePackages().
     *
     * Without it, a limit upgrade (e.g. group_limit → 4) stays hidden
     * from My Limits for up to TTL_PACKAGE (1 hour).
     */
    public static function invalidatePackage(string $packageId): void
    {
        Cache::forget(self::packageKey($packageId));
    }

    public function showLimits(string $chatId, array $from): JsonResponse
    {
        try {
            $telegramUserId = (string) ($from['id'] ?? '');

            $userId = $this->resolveUserId($telegramUserId);

            $group = $userId !== null
                ? $this->getLatestConnectedGroup($userId)
                : null;

            if (! $userId || ! $group) {
                $this->telegram->sendMessage($chatId,
                    "📊 <b>My Limits</b>\n\n"
                    . "អ្នកមិនទាន់បាន connect group នៅឡើយទេ។\n"
                    . "សូមប្រើ /connect ជាមុនសិន។",
                    ['parse_mode' => 'HTML']
                );

                return response()->json(['ok' => true]);
            }

            $subscription = $this->getActiveSubscription($userId);

            if (! $subscription) {
                $this->telegram->sendMessage($chatId,
                    "📊 <b>My Limits</b>\n\n"
                    . "អ្នកមិនទាន់មាន active subscription/package ទេ។\n"
                    . "សូមចុច 🆕 Package ដើម្បីជ្រើសរើស package។",
                    ['parse_mode' => 'HTML']
                );

                return response()->json(['ok' => true]);
            }

            $package = $this->findPackage((string) $subscription->package_id);

            /*
            |--------------------------------------------------------------------------
            | Effective limits
            |--------------------------------------------------------------------------
            |
            | FIX #1 — $package can be null (deleted/missing row): use
            |          nullsafe access so this never crashes.
            | FIX #2 — a NULL limit means UNLIMITED (matches
            |          PaymentConfirmationService::isUnlimited*), shown
            |          as ∞ instead of collapsing to 0.
            */

            $rawPaymentLimit = $subscription->override_payment_limit
                ?? $package?->payment_limit;   // null = unlimited

            $rawGroupLimit = $subscription->override_group_limit
                ?? $package?->group_limit;     // null = unlimited

            $usedGroups = (int) (
                $subscription->group_used
                ?? TelegramGroup::query()
                    ->where('user_id', $userId)
                    ->where('status', 'connected')
                    ->count()
            );

            $realPaymentCount = $this->getRealPaymentCount($userId);

            $usedPayments = max((int) ($subscription->payment_used ?? 0), $realPaymentCount);

            $paymentLimitText = $rawPaymentLimit === null
                ? '∞'
                : (string) (int) $rawPaymentLimit;

            $groupLimitText = $rawGroupLimit === null
                ? '∞'
                : (string) (int) $rawGroupLimit;

            $remainingPayments = $rawPaymentLimit === null
                ? '∞'
                : (string) max((int) $rawPaymentLimit - $usedPayments, 0);

            $remainingGroups = $rawGroupLimit === null
                ? '∞'
                : (string) max((int) $rawGroupLimit - $usedGroups, 0);

            $packageName = e(
                $package->name
                ?? $package->package_name
                ?? 'Active Package'
            );

            $text = implode("\n", [
                "📊 <b>My Limits</b>",
                "─────────────────────",
                "📦 <b>Package:</b> {$packageName}",
                "",
                "👥 <b>Groups:</b> {$usedGroups} / {$groupLimitText}",
                "✅ <b>Remaining Groups:</b> {$remainingGroups}",
                "",
                "💳 <b>Payments:</b> {$usedPayments} / {$paymentLimitText}",
                "✅ <b>Remaining Payments:</b> {$remainingPayments}",
                "─────────────────────",
            ]);

            Log::info('User checked limits', [
                'chat_id' => $chatId,
                'telegram_user_id' => $telegramUserId,
                'user_id' => $userId,
                'subscription_id' => $subscription->userSubscriptionsID ?? null,
                'package_id' => $subscription->package_id,
                'payment_limit' => $rawPaymentLimit,
                'group_limit' => $rawGroupLimit,
                'used_payments' => $usedPayments,
                'used_groups' => $usedGroups,
            ]);

            $this->telegram->sendMessage($chatId, $text, [
                'parse_mode' => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '👥 មើលក្រុមរបស់ខ្ញុំ',
                                'callback_data' => BotCallback::MY_GROUPS,
                            ],
                        ],
                        [
                            [
                                'text' => '🆕 ប្ដូរ Package',
                                'callback_data' => BotCallback::SHOW_PACKAGES,
                            ],
                        ],
                    ],
                ],
            ]);

            return response()->json(['ok' => true]);

        } catch (\Throwable $e) {
            Log::error('LimitHandler error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->telegram->sendMessage(
                $chatId,
                "⚠️ Cannot check limits now.\nPlease contact support."
            );

            return response()->json(['ok' => false]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cached lookups
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve users.uuid from the Telegram user ID of the person
     * pressing the button. Null results are NOT cached, so a user who
     * registers a moment later appears immediately.
     */
    private function resolveUserId(string $telegramUserId): ?string
    {
        if ($telegramUserId === '') {
            return null;
        }

        $cached = Cache::get(self::userLookupKey($telegramUserId));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $uuid = User::query()
            ->where('telegram_id', $telegramUserId)
            ->value('uuid');

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        Cache::put(
            self::userLookupKey($telegramUserId),
            $uuid,
            self::TTL_USER_LOOKUP
        );

        return $uuid;
    }

    private function getLatestConnectedGroup(string $userId): ?TelegramGroup
    {
        return Cache::remember(
            self::latestGroupKey($userId),
            self::TTL_GROUP,
            fn () => TelegramGroup::query()
                ->where('user_id', $userId)
                ->where('status', 'connected')
                ->latest()
                ->first()
        );
    }

    private function getActiveSubscription(string $userId): ?UserSubscription
    {
        return Cache::remember(
            self::subscriptionKey($userId),
            self::TTL_SUBSCRIPTION,
            fn () => UserSubscription::query()
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->latest('starts_at')
                ->first()
        );
    }

    private function getRealPaymentCount(string $userId): int
    {
        return (int) Cache::remember(
            self::paymentCountKey($userId),
            self::TTL_PAY_COUNT,
            fn () => TelegramPayment::query()
                ->where('user_id', $userId)
                ->where('parsed_successfully', true)
                ->where('is_duplicate', false)
                ->count()
        );
    }

    private function findPackage(string $packageId): ?object
    {
        return Cache::remember(
            self::packageKey($packageId),
            self::TTL_PACKAGE,
            function () use ($packageId): ?object {
                if (! Schema::hasTable('packages')) {
                    Log::warning('packages table does not exist');

                    return null;
                }

                // Column detection cached separately for 1 day —
                // avoids repeated information_schema queries.
                $column = Cache::remember(
                    'limits:pkg_id_column',
                    self::TTL_SCHEMA,
                    function (): ?string {
                        foreach (['id', 'package_id', 'packageID', 'packagesID'] as $col) {
                            if (Schema::hasColumn('packages', $col)) {
                                return $col;
                            }
                        }

                        return null;
                    }
                );

                if (! $column) {
                    return null;
                }

                $package = DB::table('packages')
                    ->where($column, $packageId)
                    ->first();

                if (! $package) {
                    Log::warning('Package not found for subscription', [
                        'package_id' => $packageId,
                    ]);
                }

                return $package;
            }
        );
    }

    public function showMyGroups(string $chatId, array $from): JsonResponse
    {
        try {
            $telegramUserId = (string) ($from['id'] ?? '');

            $userId = $this->resolveUserId($telegramUserId);

            $group = $userId !== null
                ? $this->getLatestConnectedGroup($userId)
                : null;

            if (! $userId || ! $group) {
                $this->telegram->sendMessage($chatId,
                    "👥 <b>ក្រុមរបស់ខ្ញុំ</b>\n\n"
                    . "អ្នកមិនទាន់បាន connect group នៅឡើយទេ។\n"
                    . "សូមប្រើ /connect ជាមុនសិន។",
                    ['parse_mode' => 'HTML']
                );

                return response()->json(['ok' => true]);
            }

            $subscription = $this->getActiveSubscription($userId);

            if (! $subscription) {
                $this->telegram->sendMessage($chatId,
                    "👥 <b>ក្រុមរបស់ខ្ញុំ</b>\n\n"
                    . "អ្នកមិនទាន់មាន active subscription/package ទេ។",
                    ['parse_mode' => 'HTML']
                );

                return response()->json(['ok' => true]);
            }

            $groups = Cache::remember(
                self::groupListKey($userId),
                self::TTL_GROUP_LIST,
                fn () => TelegramGroup::query()
                    ->where(function ($query) use ($userId, $subscription) {
                        $query->where('user_id', $userId)
                            ->orWhere('subscription_id', $subscription->userSubscriptionsID);
                    })
                    ->where('status', 'connected')
                    ->latest()
                    ->get()
            );

            if ($groups->isEmpty()) {
                $this->telegram->sendMessage($chatId,
                    "👥 <b>ក្រុមរបស់ខ្ញុំ</b>\n\n"
                    . "មិនមាន group connected ទេ។",
                    ['parse_mode' => 'HTML']
                );

                return response()->json(['ok' => true]);
            }

            $package = $this->findPackage((string) $subscription->package_id);

            /*
             * FIX #1 (nullsafe) + FIX #2 (null = unlimited → ∞).
             */
            $rawGroupLimit = $subscription->override_group_limit
                ?? $package?->group_limit;

            $usedGroups = $groups->count();

            $groupLimitText = $rawGroupLimit === null
                ? '∞'
                : (string) (int) $rawGroupLimit;

            $remainingGroups = $rawGroupLimit === null
                ? '∞'
                : (string) max((int) $rawGroupLimit - $usedGroups, 0);

            $lines = [
                "👥 <b>ក្រុមរបស់ខ្ញុំ</b>",
                "─────────────────────",
                "📊 <b>ប្រើប្រាស់:</b> {$usedGroups} / {$groupLimitText}",
                "✅ <b>នៅសល់:</b> {$remainingGroups}",
                "─────────────────────",
                "",
            ];

            foreach ($groups as $index => $telegramGroup) {
                $number = $index + 1;

                $groupName = e(
                    $telegramGroup->group_name
                    ?? $telegramGroup->title
                    ?? $telegramGroup->name
                    ?? 'Unknown Group'
                );

                $groupId = e((string) ($telegramGroup->group_id ?? 'N/A'));

                $connectedAt = $telegramGroup->created_at
                    ? $telegramGroup->created_at->format('M j, Y h:i A')
                    : 'N/A';

                $lastPaymentAt = $telegramGroup->last_payment_at
                    ? $telegramGroup->last_payment_at->format('M j, Y h:i A')
                    : 'មិនទាន់មាន payment';

                $lines[] = "{$number}. <b>{$groupName}</b>";
                $lines[] = "   🆔 <code>{$groupId}</code>";
                $lines[] = "   🔗 Connected: {$connectedAt}";
                $lines[] = "   💳 Last Payment: {$lastPaymentAt}";
                $lines[] = "";
            }

            if (
                $rawGroupLimit !== null
                && $usedGroups > (int) $rawGroupLimit
            ) {
                $lines[] = "⚠️ <b>Warning:</b> អ្នកប្រើ group លើស limit។";
            }

            $keyboard = [];

            foreach ($groups as $telegramGroup) {
                $groupName = $telegramGroup->group_name
                    ?? $telegramGroup->title
                    ?? $telegramGroup->name
                    ?? 'Unknown Group';

                $keyboard[] = [
                    [
                        'text' => '🗑 Remove ' . mb_strimwidth($groupName, 0, 25, '...'),
                        'callback_data' => BotCallback::REMOVE_GROUP_PREFIX . $telegramGroup->telegramGroupsID,
                    ],
                ];
            }

            $keyboard[] = [
                [
                    'text' => '📊 ត្រឡប់ទៅ My Limits',
                    'callback_data' => BotCallback::MY_LIMITS,
                ],
            ];

            $this->telegram->sendMessage($chatId, implode("\n", $lines), [
                'parse_mode' => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => $keyboard,
                ],
            ]);

            return response()->json(['ok' => true]);

        } catch (\Throwable $e) {
            Log::error('ShowMyGroups error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->telegram->sendMessage(
                $chatId,
                "⚠️ Cannot check groups now.\nPlease contact support."
            );

            return response()->json(['ok' => false]);
        }
    }

    public function removeGroup(
        string $chatId,
        string $telegramGroupsID,
        array $from
    ): JsonResponse {
        try {
            /*
            |--------------------------------------------------------------------------
            | Always read fresh data for write operations
            |--------------------------------------------------------------------------
            */
            $group = TelegramGroup::query()
                ->where('telegramGroupsID', $telegramGroupsID)
                ->where('status', 'connected')
                ->first();
    
            if (! $group) {
                $this->telegram->sendMessage(
                    $chatId,
                    "⚠️ <b>Group not found</b>\n\n"
                    . 'This group may already be removed.',
                    [
                        'parse_mode' => 'HTML',
                    ]
                );
    
                return response()->json([
                    'ok' => true,
                ]);
            }
    
            /*
            |--------------------------------------------------------------------------
            | Ownership check
            |--------------------------------------------------------------------------
            */
            $requesterUuid = $this->resolveUserId(
                (string) ($from['id'] ?? '')
            );
    
            if (
                $requesterUuid === null
                || $requesterUuid !== (string) $group->user_id
            ) {
                Log::warning(
                    'Unauthorized group removal attempt',
                    [
                        'chat_id' => $chatId,
                        'telegram_group_id' => $telegramGroupsID,
                        'owner_user_id' => $group->user_id,
                        'requested_by' => $from['id'] ?? null,
                    ]
                );
    
                $this->telegram->sendMessage(
                    $chatId,
                    "⚠️ អ្នកមិនមានសិទ្ធិលុប group នេះទេ។\n"
                    . 'មានតែម្ចាស់ package ប៉ុណ្ណោះដែលអាចលុបបាន។'
                );
    
                return response()->json([
                    'ok' => true,
                ]);
            }
    
            /*
            |--------------------------------------------------------------------------
            | Use the subscription attached to this group
            |--------------------------------------------------------------------------
            |
            | This is safer than loading the user's latest subscription because
            | the group could belong to another subscription.
            |
            */
            $subscription = UserSubscription::query()
                ->where(
                    'userSubscriptionsID',
                    $group->subscription_id
                )
                ->first();
    
            $rawGroupName =
                $group->group_name
                ?? $group->title
                ?? $group->name
                ?? 'Unknown Group';
    
            $groupName = e($rawGroupName);
    
            /*
            |--------------------------------------------------------------------------
            | Disconnect group
            |--------------------------------------------------------------------------
            */
            $wasDisconnected = DB::transaction(
                function () use (
                    $group,
                    $subscription
                ): bool {
                    /*
                    |--------------------------------------------------------------------------
                    | Conditional update prevents double decrement
                    |--------------------------------------------------------------------------
                    |
                    | If two remove requests arrive together, only the first request
                    | can change connected → disconnected.
                    |
                    */
                    $updated = TelegramGroup::query()
                        ->where(
                            'telegramGroupsID',
                            $group->telegramGroupsID
                        )
                        ->where(
                            'status',
                            'connected'
                        )
                        ->update([
                            'status' => 'disconnected',
    
                            'connection_status' =>
                                'offline',
    
                            'activity_status' =>
                                'inactive',
    
                            /*
                            |--------------------------------------------------------------------------
                            | Keep last_activity_at for history.
                            | Heartbeat becomes null because the connection is removed.
                            |--------------------------------------------------------------------------
                            */
                            'last_heartbeat_at' =>
                                null,
    
                            'updated_at' =>
                                now(),
                        ]);
    
                    /*
                    |--------------------------------------------------------------------------
                    | Another request already disconnected it
                    |--------------------------------------------------------------------------
                    */
                    if ($updated === 0) {
                        return false;
                    }
    
                    /*
                    |--------------------------------------------------------------------------
                    | Decrement usage safely
                    |--------------------------------------------------------------------------
                    */
                    if ($subscription) {
                        UserSubscription::query()
                            ->where(
                                'userSubscriptionsID',
                                $subscription->userSubscriptionsID
                            )
                            ->where(
                                'group_used',
                                '>',
                                0
                            )
                            ->decrement('group_used');
                    }
    
                    return true;
                }
            );
    
            /*
            |--------------------------------------------------------------------------
            | Group was already disconnected by another request
            |--------------------------------------------------------------------------
            */
            if (! $wasDisconnected) {
                return response()->json([
                    'ok' => true,
                ]);
            }
    
            /*
            |--------------------------------------------------------------------------
            | Reload updated status
            |--------------------------------------------------------------------------
            */
            $group->refresh();
    
            /*
            |--------------------------------------------------------------------------
            | Invalidate cached data
            |--------------------------------------------------------------------------
            */
            self::invalidateForUser(
                (string) $group->user_id
            );
    
            /*
            |--------------------------------------------------------------------------
            | Realtime Reverb broadcast
            |--------------------------------------------------------------------------
            */
            $this->broadcastGroupStatus($group);
    
            Log::info(
                'Telegram group removed by user',
                [
                    'chat_id' => $chatId,
    
                    'telegram_group_id' =>
                        $telegramGroupsID,
    
                    'telegram_chat_id' =>
                        $group->group_id,
    
                    'user_id' =>
                        $group->user_id,
    
                    'subscription_id' =>
                        $subscription
                            ?->userSubscriptionsID,
    
                    'connection_status' =>
                        $group->connection_status,
    
                    'activity_status' =>
                        $group->activity_status,
    
                    'removed_by' =>
                        $from['id'] ?? null,
                ]
            );
    
            /*
            |--------------------------------------------------------------------------
            | Telegram response
            |--------------------------------------------------------------------------
            */
            $this->telegram->sendMessage(
                $chatId,
                "✅ <b>Group Disconnected</b>\n\n"
                . "👥 Group: <b>{$groupName}</b>\n"
                . 'This group has been disconnected from your package.',
                [
                    'parse_mode' => 'HTML',
    
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' =>
                                        '👥 មើលក្រុមរបស់ខ្ញុំ',
    
                                    'callback_data' =>
                                        BotCallback::MY_GROUPS,
                                ],
                            ],
                            [
                                [
                                    'text' =>
                                        '📊 ត្រឡប់ទៅ My Limits',
    
                                    'callback_data' =>
                                        BotCallback::MY_LIMITS,
                                ],
                            ],
                        ],
                    ],
                ]
            );
    
            return response()->json([
                'ok' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error(
                'Remove group error',
                [
                    'message' =>
                        $e->getMessage(),
    
                    'file' =>
                        $e->getFile(),
    
                    'line' =>
                        $e->getLine(),
    
                    'telegram_group_id' =>
                        $telegramGroupsID,
    
                    'trace' =>
                        $e->getTraceAsString(),
                ]
            );
    
            $this->telegram->sendMessage(
                $chatId,
                "⚠️ Cannot remove group now.\n"
                . 'Please contact support.'
            );
    
            return response()->json([
                'ok' => false,
            ]);
        }
    }
    private function broadcastGroupStatus(
        TelegramGroup $group
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Base query
        |--------------------------------------------------------------------------
        |
        | Only currently connected groups are included in the system summary.
        | Disconnected historical groups are not counted as offline system groups.
        |
        */
        $connectedGroups = TelegramGroup::query()
            ->where('status', 'connected');
    
        /*
        |--------------------------------------------------------------------------
        | Current system counts
        |--------------------------------------------------------------------------
        */
        $totalGroups = (clone $connectedGroups)
            ->count();
    
        $onlineGroups = (clone $connectedGroups)
            ->where(
                'connection_status',
                'online'
            )
            ->count();
    
        $offlineGroups = (clone $connectedGroups)
            ->where(
                'connection_status',
                'offline'
            )
            ->count();
    
        $activeGroups = (clone $connectedGroups)
            ->where(
                'activity_status',
                'active'
            )
            ->count();
    
        /*
        |--------------------------------------------------------------------------
        | Broadcast realtime update
        |--------------------------------------------------------------------------
        */
        TelegramGroupStatusUpdated::dispatch(
            groupId:
                (string) $group->group_id,
    
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