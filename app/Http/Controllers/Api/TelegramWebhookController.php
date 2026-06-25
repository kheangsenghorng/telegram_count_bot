<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Telegram\AccountHandler;
use App\Http\Controllers\Api\Telegram\CallbackHandler;
use App\Http\Controllers\Api\Telegram\GroupHandler;
use App\Http\Controllers\Api\Telegram\PackageHandler;
use App\Http\Controllers\Api\Telegram\SupportHandler;
use App\Http\Controllers\Controller;
use App\Services\AbaPaymentService;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramBotService $telegram,
        protected AbaPaymentService  $aba,
        protected CallbackHandler    $callbackHandler,
        protected AccountHandler     $accountHandler,
        protected GroupHandler       $groupHandler,
        protected PackageHandler     $packageHandler,
        protected SupportHandler     $supportHandler,
    ) {}

    public function webhook(Request $request): JsonResponse
    {
        if (app()->isLocal()) {
            Log::info('TELEGRAM RAW', $request->all());
        }

        if ($request->has('callback_query')) {
            return $this->callbackHandler->handle($request->input('callback_query'));
        }

        $message = $request->input('message');
        if (! $message) return response()->json(['ok' => true]);

        $chat     = $message['chat'] ?? [];
        $from     = $message['from'] ?? [];
        $text     = trim($message['text'] ?? '');
        $chatId   = (string) ($chat['id'] ?? '');
        $chatType = $chat['type'] ?? 'private';   // ← extract chatType

        if (! $text || ! $chatId) return response()->json(['ok' => true]);

        // ── ABA payment ───────────────────────────────────────────────────────
        if (
            str_contains($text, 'paid by') &&
            str_contains($text, 'Trx. ID') &&
            str_contains($text, 'APV:')
        ) {
            $result = $this->aba->process($text, $chatId);
            return response()->json([
                'ok'           => true,
                'parsed'       => $result['parsed'],
                'is_duplicate' => $result['is_duplicate'],
                'currency'     => $result['currency'],
                'found_group'  => (bool) $result['group'],
                'payment_id'   => $result['payment']?->telegram_paymentID,
            ]);
        }

        // ── Ignore other bot messages ─────────────────────────────────────────
        if (! empty($from['is_bot'])) return response()->json(['ok' => true]);

        // ── Commands ──────────────────────────────────────────────────────────
        if (str_starts_with($text, '/start'))   return $this->accountHandler->start($chat, $from);
        if (str_starts_with($text, '/connect'))  return $this->groupHandler->connect($chat, $from, $text);
        if (str_starts_with($text, '/stats')) {
            $this->telegram->sendStatsMenu($chatId);
            return response()->json(['ok' => true]);
        }

        // ── Reply keyboard ────────────────────────────────────────────────────
        return match ($text) {
            '🆕 Package'          => $this->packageHandler->showPackages($chatId, $chatType),
            '🔑 My Tokens'        => $this->reply($chatId, '🔑 My Tokens selected'),
            '🌐 Domains'          => $this->reply($chatId, '🌐 Domains selected'),
            '💬 Support'          => $this->supportHandler->showContact($chatId),
            '🔒 Privacy Policy'   => $this->reply($chatId, 'https://yourdomain.com/privacy'),
            '📜 Terms of Service' => $this->reply($chatId, 'https://yourdomain.com/terms'),
            default               => response()->json(['ok' => true]),
        };
    }

    private function reply(string $chatId, string $text): JsonResponse
    {
        $this->telegram->sendMessage($chatId, $text);
        return response()->json(['ok' => true]);
    }

    public function webhookInfo(): array { return $this->telegram->webhookInfo(); }
    public function setWebhook(): array  { return $this->telegram->setWebhook(); }
    public function testMessage(): array
    {
        $chatId = config('services.telegram.test_chat_id');
        if (! $chatId) return ['ok' => false, 'message' => 'TELEGRAM_TEST_CHAT_ID not set in .env'];
        return $this->telegram->sendMessage($chatId, '✅ Telegram Bot Test Success from Laravel');
    }
}