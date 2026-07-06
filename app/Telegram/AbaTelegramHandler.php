<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Services\AbaPaymentService;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Message\GroupMessage;
use danog\MadelineProto\EventHandler\Message\PrivateMessage;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Support\Facades\Log;

final class AbaTelegramHandler extends SimpleEventHandler
{
    private ?AbaPaymentService $paymentService = null;

    public function onStart(): void
    {
        $this->paymentService = app(AbaPaymentService::class);

        Log::info('✅ AbaTelegramHandler started');

        echo "✅ Listening for ABA payments...\n";
    }

    #[Handler]
    public function onGroupMessage(GroupMessage $message): void
    {
        $this->handle($message);
    }

    #[Handler]
    public function onPrivateMessage(PrivateMessage $message): void
    {
        /*
        |--------------------------------------------------------------------------
        | Private message testing
        |--------------------------------------------------------------------------
        | In production, only listen to group messages.
        | In local/dev, private messages are useful for testing copied ABA text.
        */
        if (! app()->isLocal()) {
            return;
        }

        $this->handle($message);
    }

    private function handle(Message $message): void
    {
        $text = $this->cleanText((string) ($message->message ?? ''));
        $chatId = (string) $message->chatId;
        $messageId = $message->id ?? null;

        if ($text === '') {
            return;
        }

        $preview = mb_substr($text, 0, 90);

        echo '[' . date('H:i:s') . "] Chat: {$chatId} | Msg: {$preview}\n";

        /*
        |--------------------------------------------------------------------------
        | Ignore messages sent by your own Telegram bot
        |--------------------------------------------------------------------------
        | This prevents loop:
        | ABA message -> Laravel sends confirmation -> listener reads confirmation again.
        */
        if ($this->isOurPaymentConfirmationMessage($text)) {
            echo "⏭️ Ignored own confirmation message\n";

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Fast pre-check
        |--------------------------------------------------------------------------
        | This is only a quick filter.
        | The real parse and save logic should stay inside AbaPaymentService.
        */
        if (! $this->looksLikeAbaPayment($text)) {
            return;
        }

        echo "💳 ABA payment detected!\n";

        Log::info('ABA message detected', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'preview' => $preview,
        ]);

        try {
            $service = $this->paymentService ?? app(AbaPaymentService::class);

            $result = $service->process($text, $chatId);

            $this->printResult($result);
        } catch (\Throwable $e) {
            echo "❌ Error: {$e->getMessage()}\n";

            Log::error('AbaTelegramHandler error', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
        }
    }

    private function printResult(array $result): void
    {
        if (! ($result['parsed'] ?? false)) {
            $reason = $result['reason'] ?? 'unknown';

            echo "⚠️ Not processed — reason: {$reason}\n";

            Log::warning('ABA message not processed', [
                'reason' => $reason,
            ]);

            return;
        }

        $successCount = (int) ($result['success_count'] ?? 0);
        $duplicateCount = (int) ($result['duplicate_count'] ?? 0);
        $isDuplicate = (bool) ($result['is_duplicate'] ?? false);

        $payment = $result['payment'] ?? null;
        $trxId = $payment?->trx_id ?? 'unknown';

        if ($isDuplicate) {
            echo "⚠️ Duplicate payment ignored — Trx ID: {$trxId}\n";
        } else {
            echo "✅ Processed — Trx ID: {$trxId}\n";
        }

        Log::info('ABA payment process result', [
            'trx_id' => $trxId,
            'is_duplicate' => $isDuplicate,
            'success_count' => $successCount,
            'duplicate_count' => $duplicateCount,
        ]);
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove invisible Telegram characters.
        $text = str_replace([
            "\u{00A0}",
            "\u{200B}",
            "\u{200C}",
            "\u{200D}",
            "\u{FEFF}",
        ], ' ', $text);

        // Normalize spaces.
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{2,}/u", "\n", $text) ?? $text;

        return trim($text);
    }

    private function isOurPaymentConfirmationMessage(string $text): bool
    {
        return str_contains($text, 'ABA Payment Received')
            || str_contains($text, 'Payment confirmed')
            || str_contains($text, '💳 ABA Payment Received')
            || str_contains($text, '✅ Payment confirmed');
    }

    private function looksLikeAbaPayment(string $text): bool
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
            [៛\$＄USDKHR]+
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
}