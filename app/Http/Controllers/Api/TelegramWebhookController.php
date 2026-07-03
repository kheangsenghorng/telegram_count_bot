<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Telegram\AccountHandler;
use App\Http\Controllers\Api\Telegram\CallbackHandler;
use App\Http\Controllers\Api\Telegram\GroupHandler;
use App\Http\Controllers\Api\Telegram\LimitHandler;
use App\Http\Controllers\Api\Telegram\PackageHandler;
use App\Http\Controllers\Api\Telegram\SupportHandler;
use App\Http\Controllers\Controller;
use App\Services\AbaPaymentService;
use App\Services\ChipMongPaymentService;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramBotService $telegram,
        protected AbaPaymentService $aba,
        protected ChipMongPaymentService $acleda,
        protected CallbackHandler $callbackHandler,
        protected AccountHandler $accountHandler,
        protected GroupHandler $groupHandler,
        protected PackageHandler $packageHandler,
        protected SupportHandler $supportHandler,
        protected LimitHandler $limitHandler,
    ) {}

    public function webhook(Request $request): JsonResponse
    {
        if (app()->isLocal()) {
            Log::info('TELEGRAM RAW', $request->all());
        }

        /*
        |--------------------------------------------------------------------------
        | Callback Query
        |--------------------------------------------------------------------------
        | Inline keyboard buttons come here.
        */
        if ($request->has('callback_query')) {
            return $this->callbackHandler->handle(
                $request->input('callback_query')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Message
        |--------------------------------------------------------------------------
        */
        $message = $request->input('message');

        if (! $message) {
            return response()->json(['ok' => true]);
        }

        $chat = $message['chat'] ?? [];
        $from = $message['from'] ?? [];

        $text = trim((string) ($message['text'] ?? ''));
        $chatId = (string) ($chat['id'] ?? '');
        $chatType = (string) ($chat['type'] ?? 'private');

        if (! $text || ! $chatId) {
            return response()->json(['ok' => true]);
        }

        /*
        |--------------------------------------------------------------------------
        | ABA Payment Message
        |--------------------------------------------------------------------------
        | Important:x
        | This must be before ignoring bot messages because PayWay by ABA is a bot.
        */
        if ($this->isAbaPaymentMessage($text)) {
            $result = $this->aba->process($text, $chatId);

            return response()->json([
                'ok' => true,
                'parsed' => $result['parsed'],
                'is_duplicate' => $result['is_duplicate'],
                'currency' => $result['currency'],
                'found_group' => (bool) $result['group'],
                'payment_count' => $result['count'] ?? 0,
                'success_count' => $result['success_count'] ?? 0,
                'duplicate_count' => $result['duplicate_count'] ?? 0,
                'payment_id' => $result['payment']?->telegram_paymentID,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Chip Mong / KHQR Payment Message
        |--------------------------------------------------------------------------
        | The paying bank varies per transaction (ACLEDA Bank Plc., ABA Bank, etc.),
        | so detection is keyed on the message format, not a specific bank name.
        */
        if ($this->isChipMongPaymentMessage($text)) {
            $result = $this->acleda->process($text, $chatId);

            return response()->json([
                'ok' => true,
                'parsed' => $result['parsed'],
                'is_duplicate' => $result['is_duplicate'],
                'currency' => $result['currency'],
                'found_group' => (bool) $result['group'],
                'payment_count' => $result['count'] ?? 0,
                'success_count' => $result['success_count'] ?? 0,
                'duplicate_count' => $result['duplicate_count'] ?? 0,
                'payment_id' => $result['payment']?->telegram_paymentID,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Ignore Other Bot Messages
        |--------------------------------------------------------------------------
        */
        if (! empty($from['is_bot'])) {
            return response()->json(['ok' => true]);
        }

        /*
        |--------------------------------------------------------------------------
        | Commands
        |--------------------------------------------------------------------------
        */
        if (str_starts_with($text, '/start')) {
            return $this->accountHandler->start($chat, $from);
        }

        if (str_starts_with($text, '/connect')) {
            return $this->groupHandler->connect($chat, $from, $text);
        }

        if (str_starts_with($text, '/stats')) {
            $this->telegram->sendStatsMenu($chatId);

            return response()->json(['ok' => true]);
        }

        /*
        |--------------------------------------------------------------------------
        | Reply Keyboard Buttons
        |--------------------------------------------------------------------------
        */
        switch ($text) {
            case '🆕 Package':
                return $this->packageHandler->showPackages($chatId, $chatType);

            case '📊 My Limits':
                return $this->limitHandler->showLimits($chatId, $from);

            case '🌐 Domains':
                return $this->reply($chatId, '🌐 Domains selected');

            case '💬 Support':
                return $this->supportHandler->showContact($chatId);

            case '🔒 Privacy Policy':
                return $this->reply($chatId, 'https://yourdomain.com/privacy');

            case '📜 Terms of Service':
                return $this->reply($chatId, 'https://yourdomain.com/terms');

            default:
                return response()->json(['ok' => true]);
        }
    }

    private function isAbaPaymentMessage(string $text): bool
    {
        return str_contains($text, 'paid by')
            && str_contains($text, 'Trx. ID')
            && str_contains($text, 'APV:');
    }

    private function isChipMongPaymentMessage(string $text): bool
    {
        return str_contains($text, 'is paid by')
            && str_contains($text, 'via KHQR for purchase');
    }

    private function reply(string $chatId, string $text): JsonResponse
    {
        $this->telegram->sendMessage($chatId, $text);

        return response()->json(['ok' => true]);
    }

    public function webhookInfo(): array
    {
        return $this->telegram->webhookInfo();
    }

    public function setWebhook(): array
    {
        return $this->telegram->setWebhook();
    }

    public function testMessage(): array
    {
        $chatId = config('services.telegram.test_chat_id');

        if (! $chatId) {
            return [
                'ok' => false,
                'message' => 'TELEGRAM_TEST_CHAT_ID not set in .env',
            ];
        }

        return $this->telegram->sendMessage(
            $chatId,
            '✅ Telegram Bot Test Success from Laravel'
        );
    }
}