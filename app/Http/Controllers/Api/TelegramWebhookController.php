<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Telegram\AccountHandler;
use App\Http\Controllers\Api\Telegram\CallbackHandler;
use App\Http\Controllers\Api\Telegram\GroupHandler;
use App\Http\Controllers\Api\Telegram\LimitHandler;
use App\Http\Controllers\Api\Telegram\PackageHandler;
use App\Http\Controllers\Api\Telegram\SubscriptionLinkHandler;
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
        protected SubscriptionLinkHandler $subscriptionLink,
    ) {}

    public function webhook(Request $request): JsonResponse
    {
        if (app()->isLocal()) {
            Log::info('TELEGRAM RAW', [
                'update_id' => $request->input('update_id'),
                'has_message' => $request->has('message'),
                'has_edited_message' => $request->has('edited_message'),
                'has_callback_query' => $request->has('callback_query'),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Callback Query
        |--------------------------------------------------------------------------
        */
        if ($request->has('callback_query')) {
            return $this->callbackHandler->handle(
                $request->input('callback_query')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Message / Edited Message
        |--------------------------------------------------------------------------
        */
        $message = $request->input('message')
            ?? $request->input('edited_message');

        if (! is_array($message)) {
            return response()->json(['ok' => true]);
        }

        $chat = $message['chat'] ?? [];
        $from = $message['from'] ?? [];

        $text = $this->extractText($message);
        $chatId = (string) ($chat['id'] ?? '');
        $chatType = (string) ($chat['type'] ?? 'private');

        if ($text === '' || $chatId === '') {
            return response()->json(['ok' => true]);
        }

        /*
        |--------------------------------------------------------------------------
        | Ignore Our Bot Confirmation Message
        |--------------------------------------------------------------------------
        */
        if ($this->isOurPaymentConfirmationMessage($text)) {
            return response()->json([
                'ok' => true,
                'ignored' => 'own_payment_confirmation_message',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | ABA Payment Message
        |--------------------------------------------------------------------------
        | Important:
        | This must run before ignoring bot messages because PayWay by ABA is a bot.
        */
        if ($this->looksLikeAbaPaymentMessage($text)) {
            $result = $this->aba->process($text, $chatId);

            return $this->paymentResponse($result, 'aba');
        }

        /*
        |--------------------------------------------------------------------------
        | Chip Mong / KHQR Payment Message
        |--------------------------------------------------------------------------
        */
        if ($this->looksLikeChipMongPaymentMessage($text)) {
            $result = $this->acleda->process($text, $chatId);

            return $this->paymentResponse($result, 'chip_mong');
        }

        /*
        |--------------------------------------------------------------------------
        | Ignore Other Bot Messages
        |--------------------------------------------------------------------------
        */
        if (! empty($from['is_bot'])) {
            return response()->json([
                'ok' => true,
                'ignored' => 'other_bot_message',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Commands
        |--------------------------------------------------------------------------
        */
        if (str_starts_with($text, '/start')) {
            if ($this->subscriptionLink->handleGroupStart($message)) {
                return response()->json(['ok' => true]);
            }
        
            return $this->accountHandler->start($chat, $from, $text);   // ← pass $text
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
        return match ($text) {
            '🆕 Package' => $this->packageHandler->showPackages($chatId, $chatType),

            '📊 My Limits' => $this->limitHandler->showLimits($chatId, $from),

            '🌐 Domains' => $this->reply($chatId, '🌐 Domains selected'),

            '💬 Support' => $this->supportHandler->showContact($chatId),

            '🔒 Privacy Policy' => $this->reply($chatId, 'https://yourdomain.com/privacy'),

            '📜 Terms of Service' => $this->reply($chatId, 'https://yourdomain.com/terms'),

            default => response()->json(['ok' => true]),
        };
    }

    private function extractText(array $message): string
    {
        $text = (string) (
            $message['text']
            ?? $message['caption']
            ?? ''
        );

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $text = str_replace([
            "\u{00A0}",
            "\u{200B}",
            "\u{200C}",
            "\u{200D}",
            "\u{FEFF}",
        ], ' ', $text);

        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{2,}/u", "\n", $text) ?? $text;

        return trim($text);
    }

    private function isOurPaymentConfirmationMessage(string $text): bool
    {
        return str_contains($text, 'ABA Payment Received')
            || str_contains($text, 'Payment confirmed')
            || str_contains($text, '💳 ABA Payment Received')
            || str_contains($text, '✅ Payment confirmed')
            || str_contains($text, 'Chip Mong Payment Received')
            || str_contains($text, 'KHQR Payment Received');
    }

    private function looksLikeAbaPaymentMessage(string $text): bool
    {
        if ($this->isOurPaymentConfirmationMessage($text)) {
            return false;
        }

        return $this->looksLikeEnglishAbaPayment($text)
            || $this->looksLikeKhmerAbaPayment($text);
    }

    private function looksLikeEnglishAbaPayment(string $text): bool
    {
        /*
        |--------------------------------------------------------------------------
        | English ABA examples
        |--------------------------------------------------------------------------
        | ៛4,000 paid by BORN SOPHEAK (*021) on Jun 28, 06:13 PM via ABA PAY
        | at CHEN KHEANG. Trx. ID: 178264518833769, APV: 350810.
        |
        | ៛11,000 paid by Say Makara and Touch Chansorany (*001) on Jul 06,
        | 05:48 PM via ABA KHQR (KB PRASAC Bank Plc) at CHEN KHEANG.
        | Trx. ID: 178333489262170, APV: 348252.
        */
        return preg_match('/
            [៛\$＄]
            \s*
            [\d,]+(?:\.\d{1,2})?

            \s+paid\s+by\s+
            .+?

            \s+on\s+
            [A-Za-z]{3,9}
            \s+
            \d{1,2}
            ,\s*
            \d{1,2}:\d{2}
            \s*
            (?:AM|PM)

            \s+via\s+
            ABA
            \s+
            .+?

            \s+at\s+
            .+?

            \.\s*
            (?:Remark:\s*.+?\.\s*)?

            Trx\.\s*ID:\s*
            \d+

            ,\s*APV:\s*
            \d+
        /uix', $text) === 1;
    }

    private function looksLikeKhmerAbaPayment(string $text): bool
    {
        /*
        |--------------------------------------------------------------------------
        | Khmer ABA example
        |--------------------------------------------------------------------------
        | ៛2,000 ត្រូវបានបង់ដោយ Loek Sreymom (*016)
        | នៅថ្ងៃទី 6 ខែកក្កដា ឆ្នាំ 2026 ម៉ោង 15:33
        | តាម ABA KHQR (KB PRASAC Bank Plc) នៅ MUT SOPHEAP។
        | លេខប្រតិបត្តិការ: 178332678565175។ APV: 914741។
        */
        return preg_match('/
            [៛\$＄]
            \s*
            [\d,]+(?:\.\d{1,2})?

            \s+ត្រូវបានបង់ដោយ\s+
            .+?

            លេខប្រតិបត្តិការ:\s*
            \d+

            ។\s*APV:\s*
            \d+
        /usx', $text) === 1;
    }

    private function looksLikeChipMongPaymentMessage(string $text): bool
    {
        /*
        |--------------------------------------------------------------------------
        | Chip Mong / ACLEDA style
        |--------------------------------------------------------------------------
        | KHR 1000 is paid by ... via KHQR for purchase ...
        */
        return str_contains($text, 'is paid by')
            && str_contains($text, 'via KHQR for purchase');
    }

    private function paymentResponse(array $result, string $provider): JsonResponse
    {
        $payment = $result['payment'] ?? null;

        return response()->json([
            'ok' => true,
            'provider' => $provider,
            'parsed' => (bool) ($result['parsed'] ?? false),
            'skipped' => (bool) ($result['skipped'] ?? false),
            'reason' => $result['reason'] ?? null,

            'is_duplicate' => (bool) ($result['is_duplicate'] ?? false),
            'currency' => $result['currency'] ?? null,
            'found_group' => (bool) ($result['group'] ?? false),

            'payment_count' => (int) ($result['count'] ?? 0),
            'success_count' => (int) ($result['success_count'] ?? 0),
            'duplicate_count' => (int) ($result['duplicate_count'] ?? 0),

            'payment_id' => $payment?->telegram_paymentID,
            'trx_id' => $payment?->trx_id,
        ]);
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