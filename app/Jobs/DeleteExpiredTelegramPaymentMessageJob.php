<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PackageTransaction;
use App\Services\TelegramBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteExpiredTelegramPaymentMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $packageTransactionId,
    ) {}

    public function handle(TelegramBotService $telegram): void
    {
        $payment = PackageTransaction::find($this->packageTransactionId);

        if (! $payment) {
            Log::warning('Expire Telegram payment message skipped: transaction not found', [
                'package_transaction_id' => $this->packageTransactionId,
            ]);

            return;
        }

        if (in_array($payment->status, ['paid', 'success'], true)) {
            Log::info('Expire Telegram payment message skipped: already paid', [
                'package_transaction_id' => $payment->packageTransactionsID,
                'status' => $payment->status,
            ]);

            return;
        }

        if (
            empty($payment->telegram_chat_id) ||
            empty($payment->telegram_message_id)
        ) {
            Log::warning('Expire Telegram payment message skipped: missing Telegram IDs', [
                'package_transaction_id' => $payment->packageTransactionsID,
                'telegram_chat_id' => $payment->telegram_chat_id,
                'telegram_message_id' => $payment->telegram_message_id,
            ]);

            return;
        }

        if ($payment->status === 'pending') {
            $payment->forceFill([
                'status' => 'expired',
            ])->save();
        }

        $expiredText = "⌛ <b>QR ផុតកំណត់ហើយ</b>\n"
            . "ការទូទាត់នេះមិនអាចប្រើបានទៀតទេ។\n"
            . "សូមចុច 🆕 Package ដើម្បីបញ្ជាទិញម្ដងទៀត។";

        try {
            // Try photo caption first (QR messages are photos)
            $result = $telegram->editMessageCaption(
                (string) $payment->telegram_chat_id,
                (int) $payment->telegram_message_id,
                $expiredText
            );

            // Fallback: message is plain text, not a photo
            if (
                ($result['ok'] ?? false) !== true
                && str_contains((string) ($result['description'] ?? ''), 'no caption')
            ) {
                $result = $telegram->editPlainMessage(
                    (string) $payment->telegram_chat_id,
                    (int) $payment->telegram_message_id,
                    $expiredText
                );
            }

            if (($result['ok'] ?? false) !== true) {
                Log::warning('Telegram expire edit returned not ok', [
                    'package_transaction_id' => $payment->packageTransactionsID,
                    'telegram_chat_id' => $payment->telegram_chat_id,
                    'telegram_message_id' => $payment->telegram_message_id,
                    'telegram_response' => $result,
                ]);

                return;
            }

            Log::info('Expired Telegram payment message updated', [
                'package_transaction_id' => $payment->packageTransactionsID,
                'telegram_chat_id' => $payment->telegram_chat_id,
                'telegram_message_id' => $payment->telegram_message_id,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to expire Telegram payment message', [
                'package_transaction_id' => $payment->packageTransactionsID,
                'telegram_chat_id' => $payment->telegram_chat_id,
                'telegram_message_id' => $payment->telegram_message_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}