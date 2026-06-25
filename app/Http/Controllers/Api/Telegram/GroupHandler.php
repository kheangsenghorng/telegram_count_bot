<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Models\TelegramGroup;
use App\Models\UserSubscription;
use App\Models\SubscriptionUsageLog;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;

class GroupHandler
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    public function connect(array $chat, array $from, string $text): JsonResponse
    {
        $chatId    = (string) ($chat['id'] ?? '');
        $chatTitle = $chat['title'] ?? null;
        $chatType  = $chat['type']  ?? 'private';

        if (! in_array($chatType, ['group', 'supergroup'])) {
            $this->telegram->sendMessage($chatId, '❌ Please use /connect inside a Telegram group.');
            return response()->json(['ok' => true]);
        }

        $telegramId      = (string) ($from['id'] ?? '');
        $parts           = preg_split('/\s+/', trim($text));
        $subscriptionKey = $parts[1] ?? null;

        if ($subscriptionKey) {
            $subscription = UserSubscription::with('package')
                ->where('subscription_key', $subscriptionKey)
                ->where('status', 'active')
                ->first();
        } else {
            $subscription = UserSubscription::with(['package', 'user'])
                ->where('status', 'active')
                ->whereHas('user', fn ($q) => $q->where('telegram_id', $telegramId))
                ->latest()
                ->first();
        }

        if (! $subscription) {
            $this->telegram->sendMessage($chatId, '❌ No active subscription found. Provide a key: /connect YOUR_KEY');
            return response()->json(['ok' => true]);
        }

        if ($subscription->ends_at && now()->greaterThan($subscription->ends_at)) {
            $this->telegram->sendMessage($chatId, '❌ Subscription expired.');
            return response()->json(['ok' => true]);
        }

        $existing = TelegramGroup::where('group_id', $chatId)->first();

        if ($existing) {
            if ($existing->status === 'connected') {
                $this->telegram->sendMessage($chatId, '✅ This group is already connected.');
                return response()->json(['ok' => true]);
            }

            $existing->update([
                'user_id'         => $subscription->user_id,
                'subscription_id' => $subscription->userSubscriptionsID,
                'group_name'      => $chatTitle,
                'status'          => 'connected',
                'connected_at'    => now(),
            ]);
        } else {
            TelegramGroup::create([
                'user_id'           => $subscription->user_id,
                'subscription_id'   => $subscription->userSubscriptionsID,
                'group_id'          => $chatId,
                'group_name'        => $chatTitle,
                'group_type'        => $chatType,
                'telegram_username' => $from['username'] ?? null,
                'bot_added_at'      => now(),
                'connected_at'      => now(),
                'status'            => 'connected',
            ]);

            $subscription->increment('group_used');

            SubscriptionUsageLog::create([
                'subscription_id' => $subscription->userSubscriptionsID,
                'user_id'         => $subscription->user_id,
                'type'            => 'group',
                'action'          => 'connected',
                'value'           => 1,
                'description'     => 'Telegram group connected',
                'metadata'        => [
                    'group_id'   => $chatId,
                    'group_name' => $chatTitle,
                    'group_type' => $chatType,
                ],
            ]);
        }

        $this->telegram->sendMessage($chatId, "✅ Group connected successfully!\nGroup: {$chatTitle}");
        return response()->json(['ok' => true]);
    }
}