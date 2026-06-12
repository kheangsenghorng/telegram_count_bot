<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use App\Models\UserSubscription;
use App\Models\SubscriptionUsageLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramWebhookController extends Controller
{
    // public function webhook(Request $request)
    // {
    //     $message = $request->input('message');

    //     if (!$message) {
    //         return response()->json(['ok' => true]);
    //     }

    //     $chat = $message['chat'] ?? [];
    //     $from = $message['from'] ?? [];
    //     $text = trim($message['text'] ?? '');
    //     $chatId = (string) ($chat['id'] ?? '');

    //     if (!$text) {
    //         return response()->json(['ok' => true]);
    //     }

    //     // ABA payment message
    //    // Normalize text for trigger detection (don't modify $text itself)
    // $normalizedText = preg_replace('/^@\S+\s+/u', '', $text);
    // $normalizedText = preg_replace('/^\[.*?\]:\s*/us', '', $normalizedText);

    // if (
    //     str_contains($normalizedText, 'paid by') &&
    //     str_contains($normalizedText, 'Trx. ID') &&
    //     str_contains($normalizedText, 'APV:')
    // ) {
    //     return $this->storeAbaPayment($request, $text); // pass original $text
    // }


    //     if (str_starts_with($text, '/start')) {
    //         return $this->startAccount($chat, $from);
    //     }

    //     if (str_starts_with($text, '/connect')) {
    //         return $this->connectGroup($chat, $from, $text);
    //     }

    //     if ($text === '🆕 New Token') {
    //         return $this->reply($chatId, '🆕 New Token selected');
    //     }

    //     if ($text === '🔑 My Tokens') {
    //         return $this->reply($chatId, '🔑 My Tokens selected');
    //     }

    //     if ($text === '🌐 Domains') {
    //         return $this->reply($chatId, '🌐 Domains selected');
    //     }

    //     if ($text === '💬 Support') {
    //         return $this->reply($chatId, '💬 Support selected');
    //     }

    //     if ($text === '🔒 Privacy Policy') {
    //         return $this->reply($chatId, 'https://yourdomain.com/privacy');
    //     }

    //     if ($text === '📜 Terms of Service') {
    //         return $this->reply($chatId, 'https://yourdomain.com/terms');
    //     }

    //     return response()->json(['ok' => true]);
    // }
    public function webhook(Request $request)
{
    $message = $request->input('message');

    if (!$message) {
        return response()->json(['ok' => true]);
    }

    $chat   = $message['chat'] ?? [];
    $from   = $message['from'] ?? [];
    $text   = trim($message['text'] ?? '');
    $chatId = (string) ($chat['id'] ?? '');

    if (!$text) {
        return response()->json(['ok' => true]);
    }

    // ----------------------------------------------------------------
    // ABA payment from @PayWayByABA_bot — check this FIRST,
    // before any is_bot filtering, so bot messages are not skipped
    // ----------------------------------------------------------------
    if (
        str_contains($text, 'paid by') &&
        str_contains($text, 'Trx. ID') &&
        str_contains($text, 'APV:')
    ) {
        return $this->storeAbaPayment($request, $text);
    }

    // Skip all other bot messages — only process real user commands below
    if (!empty($from['is_bot'])) {
        return response()->json(['ok' => true]);
    }

    if (str_starts_with($text, '/start')) {
        return $this->startAccount($chat, $from);
    }

    if (str_starts_with($text, '/connect')) {
        return $this->connectGroup($chat, $from, $text);
    }

    if ($text === '🆕 New Token') {
        return $this->reply($chatId, '🆕 New Token selected');
    }

    if ($text === '🔑 My Tokens') {
        return $this->reply($chatId, '🔑 My Tokens selected');
    }

    if ($text === '🌐 Domains') {
        return $this->reply($chatId, '🌐 Domains selected');
    }

    if ($text === '💬 Support') {
        return $this->reply($chatId, '💬 Support selected');
    }

    if ($text === '🔒 Privacy Policy') {
        return $this->reply($chatId, 'https://yourdomain.com/privacy');
    }

    if ($text === '📜 Terms of Service') {
        return $this->reply($chatId, 'https://yourdomain.com/terms');
    }

    return response()->json(['ok' => true]);
}
private function storeAbaPayment(Request $request, string $text)
{
    try {
        $telegramChatId = (string) $request->input('message.chat.id');

        $group = TelegramGroup::where('group_id', $telegramChatId)
            ->where('status', 'connected')
            ->latest()
            ->first();

        $userId          = $group?->user_id;
        $subscriptionId  = $group?->subscription_id;
        $telegramGroupId = $group?->telegramGroupsID;

        if (!$group) {
            $this->sendMessage(
                $telegramChatId,
                "⚠️ Payment received but group is not connected.\n\n"
                . "Telegram Chat ID: {$telegramChatId}\n\n"
                . "Please connect this group first:\n"
                . "/connect YOUR_SUBSCRIPTION_KEY"
            );
        }

        // ----------------------------------------------------------------
        // Clean text — strip forwarded prefixes before regex matching:
        //   "@PayWayByABA_bot ៛100 paid by ..."
        //   "[12 Jun 2026 at 4:44:37 in the afternoon]:\n$100 paid by ..."
        //   "...100 paid by ..." (Telegram truncation)
        // ----------------------------------------------------------------
        $cleanText = $text;
        $cleanText = preg_replace('/^@\S+\s+/u', '', $cleanText);          // @BotName prefix
        $cleanText = preg_replace('/^\[.*?\]:\s*/us', '', $cleanText);      // [date header]:
        $cleanText = preg_replace('/^\.{3}/u', '', $cleanText);             // ... truncation dots
        $cleanText = trim($cleanText);

        // ----------------------------------------------------------------
        // Regex — matches:
        //   ៛100 paid by NAME (*acc) on Jun 12, 04:44 PM via METHOD at MERCHANT. Trx. ID: 123, APV: 456
        //   $100 paid by ...
        //   100 paid by ...  (no currency symbol after stripping)
        // ----------------------------------------------------------------
        preg_match(
            '/(?P<currency_sym>[៛$])?(?P<amount>[\d,]+(?:\.\d+)?)\s+paid by\s+(?P<payer_name>.+?)\s+\((?P<payer_account>[^)]+)\)\s+on\s+(?P<date>[A-Za-z]+\s+\d{1,2},\s*\d{1,2}:\d{2}\s*(?:AM|PM))\s+via\s+(?P<method>.+?)\s+at\s+(?P<merchant>.+?)\.\s+Trx\.\s*ID:\s*(?P<trx_id>\d+),\s*APV:\s*(?P<apv>\d+)/ui',
            $cleanText,
            $match
        );

        if (!isset($match['trx_id'])) {
            Log::warning('ABA parse failed', [
                'original_text' => $text,
                'clean_text'    => $cleanText,
                'chat_id'       => $telegramChatId,
            ]);

            $payment = TelegramPayment::create([
                'user_id'             => $userId,
                'subscription_id'     => $subscriptionId,
                'telegram_group_id'   => $telegramGroupId,
                'raw_message'         => $text,
                'status'              => 'pending',
                'parsed_successfully' => false,
                'is_duplicate'        => false,
            ]);

            return response()->json([
                'ok'              => true,
                'message'         => 'Text saved but not parsed',
                'telegram_chat_id'=> $telegramChatId,
                'clean_text'      => $cleanText,   // helpful for debugging
                'found_group'     => (bool) $group,
                'data'            => $payment,
            ]);
        }

        // ----------------------------------------------------------------
        // Currency
        // ----------------------------------------------------------------
        $currency = ($match['currency_sym'] ?? '') === '៛' ? 'KHR' : 'USD';

        // ----------------------------------------------------------------
        // Date — "Jun 12, 04:44 PM"
        // ----------------------------------------------------------------
        try {
            $paymentDate = Carbon::createFromFormat('M d, h:i A', trim($match['date']))
                ->year(now()->year);
        } catch (\Throwable $e) {
            try {
                $cleanDate   = preg_replace('/\s+/', ' ', trim($match['date']));
                $paymentDate = Carbon::parse($cleanDate);
            } catch (\Throwable $e2) {
                $paymentDate = now();
            }
        }

        // ----------------------------------------------------------------
        // Duplicate check + upsert
        // ----------------------------------------------------------------
        $trxId           = trim($match['trx_id']);
        $existingPayment = TelegramPayment::where('trx_id', $trxId)->first();

        $payment = TelegramPayment::updateOrCreate(
            ['trx_id' => $trxId],
            [
                'user_id'             => $userId,
                'subscription_id'     => $subscriptionId,
                'telegram_group_id'   => $telegramGroupId,
                'currency'            => $currency,
                'amount'              => (float) str_replace(',', '', $match['amount']),
                'payer_name'          => trim($match['payer_name']),
                'payer_account'       => trim($match['payer_account']),
                'merchant_name'       => trim($match['merchant']),
                'payment_method'      => trim($match['method']),
                'bank_code'           => 'ABA',
                'trx_id'              => $trxId,
                'apv'                 => trim($match['apv']),
                'payment_date'        => $paymentDate,
                'report_date'         => $paymentDate->toDateString(),
                'report_month'        => $paymentDate->month,
                'report_year'         => $paymentDate->year,
                'raw_message'         => $text,       // always store original
                'status'              => 'success',
                'parsed_successfully' => true,
                'is_duplicate'        => (bool) $existingPayment,
            ]
        );

        $group?->update(['last_payment_at' => now()]);

        return response()->json([
            'ok'               => true,
            'message'          => 'ABA payment saved',
            'telegram_chat_id' => $telegramChatId,
            'found_group'      => (bool) $group,
            'user_id'          => $userId,
            'subscription_id'  => $subscriptionId,
            'telegram_group_id'=> $telegramGroupId,
            'is_duplicate'     => (bool) $existingPayment,
            'data'             => $payment,
        ]);

    } catch (\Throwable $e) {
        Log::error('ABA payment save error', [
            'message' => $e->getMessage(),
            'line'    => $e->getLine(),
            'file'    => $e->getFile(),
        ]);

        return response()->json([
            'ok'      => false,
            'message' => $e->getMessage(),
            'line'    => $e->getLine(),
            'file'    => $e->getFile(),
        ], 500);
    }
}

    private function startAccount(array $chat, array $from)
    {
        $chatId = (string) ($chat['id'] ?? '');
        $chatType = $chat['type'] ?? 'private';

        if ($chatType !== 'private') {
            return $this->reply($chatId, '👋 Please open bot private and send /start.');
        }

        $telegramId = (string) ($from['id'] ?? '');
        $firstName = $from['first_name'] ?? 'Telegram';
        $lastName = $from['last_name'] ?? null;
        $username = $from['username'] ?? null;

        $user = User::firstOrCreate(
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

        $user->update([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'telegram_username' => $username,
            'telegram_first_name' => $firstName,
            'telegram_last_name' => $lastName,
        ]);

        $this->sendMainMenu(
            $chatId,
            "✅ Account ready!\n\nHello {$firstName}\nTelegram ID: {$telegramId}"
        );

        return response()->json([
            'ok' => true,
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
            return $this->reply($chatId, '❌ Please use /connect inside Telegram group.');
        }

        $telegramId = (string) ($from['id'] ?? '');
        $parts = preg_split('/\s+/', trim($text));
        $subscriptionKey = $parts[1] ?? null;

        if (!$subscriptionKey) {
            $subscription = UserSubscription::with(['package', 'user'])
                ->where('status', 'active')
                ->whereHas('user', function ($query) use ($telegramId) {
                    $query->where('telegram_id', $telegramId);
                })
                ->latest()
                ->first();

            if (!$subscription) {
                return $this->reply($chatId, '❌ No active subscription found.');
            }

            $subscriptionKey = $subscription->subscription_key;
        }

        $subscription = UserSubscription::with('package')
            ->where('subscription_key', $subscriptionKey)
            ->where('status', 'active')
            ->first();

        if (!$subscription) {
            return $this->reply($chatId, '❌ Invalid or inactive subscription key.');
        }

        if ($subscription->ends_at && now()->greaterThan($subscription->ends_at)) {
            return $this->reply($chatId, '❌ Subscription expired.');
        }

        $exists = TelegramGroup::where('group_id', $chatId)->first();

        if ($exists) {
            return $this->reply($chatId, '✅ This group is already connected.');
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

        return $this->reply($chatId, "✅ Group connected successfully!\nGroup: {$chatTitle}");
    }

    private function sendMainMenu($chatId, string $text)
    {
        return $this->telegramApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => [
                'keyboard' => [
                    [
                        ['text' => '🆕 New Token'],
                        ['text' => '🔑 My Tokens'],
                    ],
                    [
                        ['text' => '🌐 Domains'],
                        ['text' => '💬 Support'],
                    ],
                    [
                        ['text' => '🔒 Privacy Policy'],
                        ['text' => '📜 Terms of Service'],
                    ],
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => false,
                'is_persistent' => true,
            ],
        ]);
    }

    private function reply($chatId, string $text)
    {
        $this->sendMessage($chatId, $text);

        return response()->json(['ok' => true]);
    }

    private function sendMessage($chatId, string $text)
    {
        return $this->telegramApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
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

    public function testMessage()
    {
        return $this->telegramApi('sendMessage', [
            'chat_id' => '-1004248571145',
            'text' => '✅ Telegram Bot Test Success from Laravel',
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