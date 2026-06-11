<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramGroup;
use App\Models\UserSubscription;
use App\Models\SubscriptionUsageLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TelegramWebhookController extends Controller
{
    public function webhook(Request $request)
    {
        $message = $request->input('message');

        if (!$message) {
            return response()->json(['ok' => true]);
        }

        $chat = $message['chat'] ?? [];
        $from = $message['from'] ?? [];
        $text = trim($message['text'] ?? '');

        if (str_starts_with($text, '/start')) {
            return $this->startAccount($chat, $from);
        }

        if (str_starts_with($text, '/connect')) {
            return $this->connectGroup($chat, $from, $text);
        }

        return response()->json(['ok' => true]);
    }

    private function startAccount(array $chat, array $from)
    {
        $chatId = (string) ($chat['id'] ?? '');
        $chatType = $chat['type'] ?? 'private';

        if ($chatType !== 'private') {
            $this->sendMessage($chatId, "👋 Please open bot private and send /start.");
            return response()->json(['ok' => true]);
        }

        $telegramId = (string) ($from['id'] ?? '');
        $firstName = $from['first_name'] ?? 'Telegram';
        $lastName = $from['last_name'] ?? null;
        $username = $from['username'] ?? null;

        $user = User::updateOrCreate(
            ['telegram_id' => $telegramId],
            [
                'uuid' => (string) Str::uuid(),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => 'telegram_' . $telegramId . '@telegram.local',
                'telegram_username' => $username,
                'telegram_first_name' => $firstName,
                'telegram_last_name' => $lastName,
                'password' => bcrypt(Str::random(32)),
                'role' => 'user',
                'status' => 'active',
            ]
        );

        $this->sendMessage(
            $chatId,
            "✅ Account ready!\n\nHello {$firstName}\nTelegram ID: {$telegramId}"
        );

        return response()->json([
            'ok' => true,
            'user_id' => $user->id,
            'uuid' => $user->uuid,
            'telegram_id' => $telegramId,
        ]);
    }

    private function connectGroup(array $chat, array $from, string $text)
    {
        $chatId = (string) ($chat['id'] ?? '');
        $chatTitle = $chat['title'] ?? null;
        $chatType = $chat['type'] ?? 'private';

        if (!in_array($chatType, ['group', 'supergroup'])) {
            $this->sendMessage($chatId, '❌ Please use /connect inside Telegram group.');
            return response()->json(['ok' => true]);
        }

        $subscriptionKey = explode(' ', $text)[1] ?? null;

        if (!$subscriptionKey) {
            $this->sendMessage($chatId, 'Usage: /connect SUB-XXXXX');
            return response()->json(['ok' => true]);
        }

        $subscription = UserSubscription::with('package')
            ->where('subscription_key', $subscriptionKey)
            ->where('status', 'active')
            ->first();

        if (!$subscription) {
            $this->sendMessage($chatId, '❌ Invalid or inactive subscription key.');
            return response()->json(['ok' => true]);
        }

        if ($subscription->ends_at && now()->greaterThan($subscription->ends_at)) {
            $this->sendMessage($chatId, '❌ Subscription expired.');
            return response()->json(['ok' => true]);
        }

        $exists = TelegramGroup::where('group_id', $chatId)->first();

        if ($exists) {
            $this->sendMessage($chatId, '✅ This group is already connected.');
            return response()->json(['ok' => true]);
        }

        $limit = $subscription->override_group_limit ?? $subscription->package?->group_limit;

        $currentGroups = TelegramGroup::where('subscription_id', $subscription->userSubscriptionsID)
            ->where('status', 'connected')
            ->count();

        if ($limit !== null && $currentGroups >= $limit) {
            $this->sendMessage($chatId, '❌ Group limit reached for this package.');
            return response()->json(['ok' => true]);
        }

        TelegramGroup::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->userSubscriptionsID,
            'group_id' => $chatId,
            'group_name' => $chatTitle,
            'group_type' => $chatType,
            'telegram_username' => $from['username'] ?? null,
            'bot_added_at' => now(),
            'connected_at' => now(),
            'status' => 'connected',
        ]);

        $subscription->increment('group_used');

        SubscriptionUsageLog::create([
            'subscription_id' => $subscription->userSubscriptionsID,
            'user_id' => $subscription->user_id,
            'type' => 'group',
            'action' => 'connected',
            'value' => 1,
            'description' => 'Telegram group connected',
            'metadata' => [
                'group_id' => $chatId,
                'group_name' => $chatTitle,
                'group_type' => $chatType,
            ],
        ]);

        $this->sendMessage($chatId, "✅ Group connected successfully!\nGroup: {$chatTitle}");

        return response()->json(['ok' => true]);
    }

    public function testConnect(Request $request)
    {
        if (!app()->environment('local')) {
            abort(404);
        }
    
        $key = $request->query('key');
    
        if (!$key) {
            return response()->json([
                'success' => false,
                'message' => 'Missing key'
            ], 422);
        }
    
        $chatId = '-1003752923861';
    
        $this->sendMessage(
            $chatId,
            "🧪 Testing subscription key: {$key}"
        );
    
        return response()->json([
            'success' => true,
            'chat_id' => $chatId,
            'key' => $key,
        ]);
    }
    public function getUpdates()
    {
        if (!app()->environment('local')) {
            abort(404);
        }

        return $this->telegramApi('getUpdates');
    }

    public function deleteWebhook()
    {
        if (!app()->environment('local')) {
            abort(404);
        }

        return $this->telegramApi('deleteWebhook');
    }

    public function webhookInfo()
    {
        return $this->telegramApi('getWebhookInfo');
    }

    public function setWebhook()
    {
        $url = rtrim(config('app.url'), '/') . '/api/telegram/webhook';

        return $this->telegramApi('setWebhook', [
            'url' => $url,
        ]);
    }

    private function sendMessage($chatId, string $text)
    {
        return $this->telegramApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    private function telegramApi(string $method, array $data = [])
    {
        $token = config('services.telegram.bot_token');

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Missing TELEGRAM_BOT_TOKEN',
            ], 500);
        }

        $url = "https://api.telegram.org/bot{$token}/{$method}";

        if (empty($data)) {
            return Http::get($url)->json();
        }

        return Http::post($url, $data)->json();
    }
}