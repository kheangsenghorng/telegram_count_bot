<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Helpers\KhmerDateFormatter;
use App\Models\PackageTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class PaymentSuccessNotifier
{
    /**
     * Send the Khmer payment-success message to the chat where the
     * checkout message was originally posted, with an
     * "add bot to group" button.
     */
    public function notify(
        PackageTransaction $payment,
        object $subscription,
    ): bool {
        $chatId = trim((string) $payment->telegram_chat_id);

        if ($chatId === '') {
            return false;
        }

        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            Log::warning(
                'Telegram bot token missing — cannot send success message.'
            );

            return false;
        }

        $packageName = trim(
            (string) optional($payment->package)->name
        );

        // ៤.៩៩ ដុល្លារ / ២០,០០០ រៀល
        $amount = KhmerDateFormatter::formatCurrency(
            $payment->amount,
            (string) $payment->currency
        );

        $lines = [
            '✅ ការទូទាត់ជោគជ័យ!',
            '',
        ];

        if ($packageName !== '') {
            $lines[] = "📦 កញ្ចប់៖ {$packageName}";
        }

        $lines[] = "💵 ចំនួន៖ {$amount}";

        $endDate = $this->khmerDate(
            $subscription->end_date ?? null
        );

        if ($endDate !== null) {
            $lines[] = "📅 ផុតកំណត់៖ {$endDate}";
        }

        $lines[] = '';
        $lines[] = 'សូមអរគុណសម្រាប់ការគាំទ្រ! 🙏';
        $lines[] = '';
        $lines[] =
            '👇 ចុចប៊ូតុងខាងក្រោម ដើម្បីបញ្ចូល Bot ទៅក្នុងក្រុមរបស់អ្នក';

        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => implode("\n", $lines),
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' =>
                                    '➕ បញ្ចូល Bot ទៅក្នុងក្រុម',
                                'url' => $this->addToGroupUrl(),
                            ],
                        ],
                    ],
                ]),
            ]
        );

        if ($response->json('ok') !== true) {
            Log::warning('Payment success message failed', [
                'chat_id' => $chatId,
                'description' =>
                    $response->json('description'),
            ]);

            return false;
        }

        return true;
    }

   /**
     * Deep link that opens Telegram's group picker and adds the bot
     * to the selected group.
     *
     * The `startgroup` value arrives in the group as:
     *   /start@sumpayment_bot connect
     * so the listener can treat it exactly like the /connect command
     * and auto-register the group.
     */
    private function addToGroupUrl(): string
    {
        $botUsername = ltrim(
            (string) config(
                'services.telegram.bot_username',
                'sumpayment_bot'
            ),
            '@'
        );

        return sprintf(
            'https://t.me/%s?startgroup=connect&admin=delete_messages+pin_messages',
            $botUsername
        );
    }

    /**
     * Format a date as "១៥ សីហា ២០២៦" in Asia/Phnom_Penh using
     * KhmerDateFormatter::formatDate().
     *
     * Returns null for empty / unparseable values.
     */
    private function khmerDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            $date = Carbon::parse($value)
                ->timezone('Asia/Phnom_Penh');
        } catch (\Throwable) {
            return null;
        }

        return KhmerDateFormatter::formatDate($date);
    }
}