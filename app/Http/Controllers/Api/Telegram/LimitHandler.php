<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Constants\BotCallback;
use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use App\Models\UserSubscription;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Cache key builders (public so other services can invalidate too)
    |--------------------------------------------------------------------------
    */
    public static function latestGroupKey(): string
    {
        return 'limits:latest_group';
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

    /**
     * Call this from your payment save handler after a new payment is counted,
     * and from PaymentConfirmationService::activatePackage().
     */
    public static function invalidateForUser(string $userId): void
    {
        Cache::forget(self::subscriptionKey($userId));
        Cache::forget(self::paymentCountKey($userId));
        Cache::forget(self::groupListKey($userId));
        Cache::forget(self::latestGroupKey());
    }

    public function showLimits(string $chatId, array $from): JsonResponse
    {
        try {
            $telegramUserId = (string) ($from['id'] ?? '');

            $group = $this->getLatestConnectedGroup();

            if (! $group) {
                $this->telegram->sendMessage($chatId,
                    "📊 <b>My Limits</b>\n\n"
                    . "អ្នកមិនទាន់បាន connect group នៅឡើយទេ។\n"
                    . "សូមប្រើ /connect ជាមុនសិន។",
                    ['parse_mode' => 'HTML']
                );

                return response()->json(['ok' => true]);
            }

            $subscription = $this->getActiveSubscription((string) $group->user_id);

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

            $paymentLimit = (int) (
                $subscription->override_payment_limit
                ?? ($package->payment_limit ?? 0)
            );

            $groupLimit = (int) (
                $subscription->override_group_limit
                ?? ($package->group_limit ?? 0)
            );

            $usedGroups = (int) (
                $subscription->group_used
                ?? TelegramGroup::query()
                    ->where('user_id', $group->user_id)
                    ->where('status', 'connected')
                    ->count()
            );

            $realPaymentCount = $this->getRealPaymentCount((string) $group->user_id);

            $usedPayments = max((int) ($subscription->payment_used ?? 0), $realPaymentCount);

            $remainingGroups = max($groupLimit - $usedGroups, 0);
            $remainingPayments = max($paymentLimit - $usedPayments, 0);

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
                "👥 <b>Groups:</b> {$usedGroups} / {$groupLimit}",
                "✅ <b>Remaining Groups:</b> {$remainingGroups}",
                "",
                "💳 <b>Payments:</b> {$usedPayments} / {$paymentLimit}",
                "✅ <b>Remaining Payments:</b> {$remainingPayments}",
                "─────────────────────",
            ]);

            Log::info('User checked limits', [
                'chat_id' => $chatId,
                'telegram_user_id' => $telegramUserId,
                'user_id' => $group->user_id,
                'subscription_id' => $subscription->userSubscriptionsID ?? null,
                'package_id' => $subscription->package_id,
                'payment_limit' => $paymentLimit,
                'group_limit' => $groupLimit,
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

    private function getLatestConnectedGroup(): ?TelegramGroup
    {
        return Cache::remember(
            self::latestGroupKey(),
            self::TTL_GROUP,
            fn () => TelegramGroup::query()
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
                ->latest()
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
            $group = $this->getLatestConnectedGroup();

            if (! $group) {
                $this->telegram->sendMessage($chatId,
                    "👥 <b>ក្រុមរបស់ខ្ញុំ</b>\n\n"
                    . "អ្នកមិនទាន់បាន connect group នៅឡើយទេ។\n"
                    . "សូមប្រើ /connect ជាមុនសិន។",
                    ['parse_mode' => 'HTML']
                );

                return response()->json(['ok' => true]);
            }

            $subscription = $this->getActiveSubscription((string) $group->user_id);

            if (! $subscription) {
                $this->telegram->sendMessage($chatId,
                    "👥 <b>ក្រុមរបស់ខ្ញុំ</b>\n\n"
                    . "អ្នកមិនទាន់មាន active subscription/package ទេ។",
                    ['parse_mode' => 'HTML']
                );

                return response()->json(['ok' => true]);
            }

            $groups = Cache::remember(
                self::groupListKey((string) $group->user_id),
                self::TTL_GROUP_LIST,
                fn () => TelegramGroup::query()
                    ->where(function ($query) use ($group, $subscription) {
                        $query->where('user_id', $group->user_id)
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

            $groupLimit = (int) (
                $subscription->override_group_limit
                ?? ($package->group_limit ?? 0)
            );

            $usedGroups = $groups->count();
            $remainingGroups = max($groupLimit - $usedGroups, 0);

            $lines = [
                "👥 <b>ក្រុមរបស់ខ្ញុំ</b>",
                "─────────────────────",
                "📊 <b>ប្រើប្រាស់:</b> {$usedGroups} / {$groupLimit}",
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

            if ($usedGroups > $groupLimit) {
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

    public function removeGroup(string $chatId, string $telegramGroupsID, array $from): JsonResponse
    {
        try {
            // Write path: ALWAYS read fresh from DB, never from cache.
            $group = TelegramGroup::query()
                ->where('telegramGroupsID', $telegramGroupsID)
                ->where('status', 'connected')
                ->first();

            if (! $group) {
                $this->telegram->sendMessage($chatId,
                    "⚠️ <b>Group not found</b>\n\n"
                    . "This group may already be removed.",
                    ['parse_mode' => 'HTML']
                );

                return response()->json(['ok' => true]);
            }

            $subscription = UserSubscription::query()
                ->where('user_id', $group->user_id)
                ->where('status', 'active')
                ->latest()
                ->first();

            $groupName = e(
                $group->group_name
                ?? $group->title
                ?? $group->name
                ?? 'Unknown Group'
            );

            DB::transaction(function () use ($subscription, $telegramGroupsID) {
                TelegramGroup::query()
                    ->where('telegramGroupsID', $telegramGroupsID)
                    ->where('status', 'connected')
                    ->update([
                        'status' => 'disconnected',
                        'updated_at' => now(),
                    ]);

                if ($subscription && (int) $subscription->group_used > 0) {
                    UserSubscription::query()
                        ->where('userSubscriptionsID', $subscription->userSubscriptionsID)
                        ->decrement('group_used');
                }
            });

            /*
            |--------------------------------------------------------------------------
            | Cache invalidation — data changed, bust everything for this user
            |--------------------------------------------------------------------------
            */
            self::invalidateForUser((string) $group->user_id);

            Log::info('Telegram group removed by user', [
                'chat_id' => $chatId,
                'telegram_group_id' => $telegramGroupsID,
                'user_id' => $group->user_id,
                'subscription_id' => $subscription->userSubscriptionsID ?? null,
                'removed_by' => $from['id'] ?? null,
            ]);

            $this->telegram->sendMessage($chatId,
                "✅ <b>Group Disconnected</b>\n\n"
                . "👥 Group: <b>{$groupName}</b>\n"
                . "This group has been disconnected from your package.",
                [
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
                                    'text' => '📊 ត្រឡប់ទៅ My Limits',
                                    'callback_data' => BotCallback::MY_LIMITS,
                                ],
                            ],
                        ],
                    ],
                ]
            );

            return response()->json(['ok' => true]);

        } catch (\Throwable $e) {
            Log::error('Remove group error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'telegram_group_id' => $telegramGroupsID,
                'trace' => $e->getTraceAsString(),
            ]);

            $this->telegram->sendMessage(
                $chatId,
                "⚠️ Cannot remove group now.\nPlease contact support."
            );

            return response()->json(['ok' => false]);
        }
    }
}