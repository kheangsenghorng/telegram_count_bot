<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\PackageTransaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TelegramMessageCleanup
{
    /**
     * Delete the checkout message (QR / pay button) after payment.
     *
     * Safe to call multiple times — Telegram returns an error on the
     * second delete, which we swallow. Never throws.
     */
    public function deleteCheckoutMessage(PackageTransaction $payment): bool
    {
        $chatId = trim((string) $payment->telegram_chat_id);
        $messageId = (int) $payment->telegram_message_id;

        if ($chatId === '' || $messageId <= 0) {
            return false;
        }

        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            Log::warning('Telegram bot token missing — cannot delete checkout message.');

            return false;
        }

        try {
            $response = Http::timeout(10)->post(
                "https://api.telegram.org/bot{$token}/deleteMessage",
                [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ]
            );

            if ($response->json('ok') === true) {
                return true;
            }

            // Already deleted / too old (>48h) / not found — not fatal.
            Log::info('Telegram deleteMessage skipped', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'description' => $response->json('description'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram deleteMessage failed', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }
}