<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChipMongPaymentService
{
    private const CURRENCY_MAP = [
        'KHR' => 'KHR',
        'USD' => 'USD',
        '៛'   => 'KHR',
        '$'   => 'USD',
        '＄'  => 'USD',
    ];

    /**
     * Supports:
     *
     * KHR 16,000 is paid by ABA Bank via KHQR for purchase 4a335f70.
     * From MOUYLEANG MOUERN, at CHEN KHEANG, date Jul 06, 2026 10:08 AM
     *
     * USD 2.50 is paid by ABA Bank via KHQR for purchase 44462a1b.
     * From KAO HENG, at CHEN KHEANG, date Jul 06, 2026 03:21 PM
     *
     * KHR 4,000 is paid by Wing Bank (Cambodia) Plc via KHQR for purchase b5dfdee8.
     * From Chamroeun Ruos, at CHEN KHEANG, date Jul 06, 2026 04:08 PM
     *
     * ACLEDA to Chip Mong Pay KHR 6,500 is paid by ACLEDA Bank Plc. via KHQR...
     */private const PATTERN = '/
    (?:ACLEDA\s+to\s+(?P<merchant_prefix>.+?)\s+)?

    (?P<currency_code>KHR|USD|[៛\$＄])
    \s*
    (?P<amount>[\d,]+(?:\.\d+)?)

    \s+is\s+paid\s+by\s+
    (?P<bank>.+?)

    \s+via\s+KHQR\s+for\s+purchase\s+
    (?P<order_ref>[A-Za-z0-9]+)

    \.\s+From\s+
    (?P<payer_name>.+?)

    ,\s+at\s+
    (?P<location>.*?)

    (?:,\s+(?P<account_type>
        Merchant\s+account|
        Saving\s+account|
        Current\s+account
    ))?

    ,\s+date\s+
    (?P<date>
        [A-Za-z]+\s+\d{1,2},\s*
        \d{4}\s+
        \d{1,2}:\d{2}\s*
        (?:AM|PM)
    )
/uix';

    public function __construct(
        protected TelegramBotService $telegram
    ) {}

    public function process(string $rawText, string|int $telegramChatId): array
    {
        $telegramChatId = (string) $telegramChatId;
        $cleanText = $this->cleanText($rawText);

        $group = $this->findGroup($telegramChatId);

        $userId = $group?->user_id;
        $subscriptionId = $group?->subscription_id;
        $telegramGroupId = $group?->telegramGroupsID;

        preg_match_all(self::PATTERN, $cleanText, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            Log::warning('Chip Mong payment parse failed', [
                'chat_id' => $telegramChatId,
                'original' => $rawText,
                'clean' => $cleanText,
            ]);

            $payment = TelegramPayment::create([
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'telegram_group_id' => $telegramGroupId,
                'currency' => null,
                'raw_message' => $rawText,
                'status' => 'pending',
                'parsed_successfully' => false,
                'is_duplicate' => false,
            ]);

            if ($group) {
                $this->telegram->sendMarkdown(
                    $telegramChatId,
                    "⚠️ *Chip Mong Payment Received — Parse Failed*\n\n"
                    . "Could not read the message automatically.\n"
                    . "Raw text saved for manual review.\n\n"
                    . "```\n{$cleanText}\n```"
                );
            }

            return [
                'parsed' => false,
                'group' => $group,
                'payment' => $payment,
                'payments' => [$payment],
                'currency' => null,
                'is_duplicate' => false,
                'count' => 0,
                'success_count' => 0,
                'duplicate_count' => 0,
            ];
        }

        $savedPayments = [];
        $duplicateCount = 0;
        $successCount = 0;

        foreach ($matches as $match) {
            $currency = $this->detectCurrency($match['currency_code'] ?? '');
            $paymentDate = $this->parseDate($match['date'] ?? '');
            $orderRef = trim($match['order_ref'] ?? '');

            $amount = (float) str_replace(',', '', $match['amount'] ?? '0');
            $payerName = trim($match['payer_name'] ?? '');
            $bank = trim($match['bank'] ?? '');
            $merchant = ! empty($match['merchant_prefix'])
                ? trim($match['merchant_prefix'])
                : 'Chip Mong Pay';

            $location = trim($match['location'] ?? '');
            $accountType = ! empty($match['account_type'])
                ? trim($match['account_type'])
                : null;

            $paymentMethod = $this->normalizePaymentMethod($bank);

            $payment = TelegramPayment::updateOrCreate(
                [
                    'trx_id' => $orderRef,
                ],
                [
                    'user_id' => $userId,
                    'subscription_id' => $subscriptionId,
                    'telegram_group_id' => $telegramGroupId,

                    'currency' => $currency,
                    'amount' => $amount,
                    'payer_name' => $payerName,
                    'payer_account' => $accountType,
                    'merchant_name' => $merchant,
                    'payment_method' => $paymentMethod,
                    'bank_code' => $bank,
                    'trx_id' => $orderRef,
                    'apv' => null,

                    'payment_date' => $paymentDate,
                    'report_date' => $paymentDate->toDateString(),
                    'report_month' => $paymentDate->month,
                    'report_year' => $paymentDate->year,

                    'raw_message' => $rawText,
                    'status' => 'success',
                    'parsed_successfully' => true,
                    'is_duplicate' => false,
                ]
            );

            $isDuplicate = ! $payment->wasRecentlyCreated;

            if ($isDuplicate) {
                $duplicateCount++;

                $payment->update([
                    'is_duplicate' => true,
                ]);
            } else {
                $successCount++;
                $this->incrementPaymentUsed($subscriptionId);

                if ($group) {
                    $this->sendPaymentAlert(
                        telegramChatId: $telegramChatId,
                        amount: $amount,
                        currency: $currency,
                        payerName: $payerName,
                        bank: $bank,
                        merchant: $merchant,
                        location: $location,
                        accountType: $accountType,
                        paymentDate: $paymentDate,
                        orderRef: $orderRef,
                        paymentMethod: $paymentMethod,
                    );
                }
            }

            $savedPayments[] = $payment;
        }

        $group?->update([
            'last_payment_at' => now(),
        ]);

        return [
            'parsed' => true,
            'group' => $group,
            'payment' => $savedPayments[0] ?? null,
            'payments' => $savedPayments,
            'currency' => $savedPayments[0]?->currency ?? null,
            'is_duplicate' => $duplicateCount > 0,
            'count' => count($savedPayments),
            'success_count' => $successCount,
            'duplicate_count' => $duplicateCount,
        ];
    }

    private function sendPaymentAlert(
        string $telegramChatId,
        float $amount,
        string $currency,
        string $payerName,
        string $bank,
        string $merchant,
        string $location,
        ?string $accountType,
        Carbon $paymentDate,
        string $orderRef,
        string $paymentMethod,
    ): void {
        $symbol = $currency === 'KHR' ? '៛' : '$';
        $decimals = $currency === 'KHR' ? 0 : 2;

        $formattedAmount = number_format($amount, $decimals);

        $lines = [
            "💳 *Chip Mong Bank Payment Received*",
            "━━━━━━━━━━━━━━━━━━",
            "💰 *Amount:*    `{$symbol}{$formattedAmount}`",
            "👤 *Payer:*     `{$payerName}`",
            "🏪 *Merchant:*  `{$merchant}`",
            "🏦 *Bank:*      `{$bank}`",
            "💳 *Method:*    `{$paymentMethod}`",
            "📍 *Location:*  `{$location}`",
        ];

        if ($accountType) {
            $lines[] = "🏷️ *Account:*   `{$accountType}`";
        }

        $lines[] = "📅 *Date:*      `{$paymentDate->format('M j, Y h:i A')}`";
        $lines[] = "🔖 *Order Ref:* `{$orderRef}`";
        $lines[] = "━━━━━━━━━━━━━━━━━━";
        $lines[] = "✅ Payment confirmed";

        $this->telegram->sendMarkdown($telegramChatId, implode("\n", $lines));
    }

    private function findGroup(string $chatId): ?TelegramGroup
    {
        $group = TelegramGroup::where('group_id', $chatId)
            ->where('status', 'connected')
            ->latest()
            ->first();

        if (! $group) {
            Log::notice('ChipMongPaymentService: no connected group for chat', [
                'chat_id' => $chatId,
            ]);
        }

        return $group;
    }

    private function cleanText(string $text): string
    {
        $text = trim($text);

        // Remove Telegram exported header:
        // Chip Mong Bank Payment, [6 Jul 2026 at 10:08:55 in the morning]:
        $text = preg_replace('/^.+?,\s*\[.*?\]:\s*/um', '', $text);

        $text = preg_replace('/^@\S+\s+/um', '', $text);
        $text = preg_replace('/^\[.*?\]:\s*/um', '', $text);
        $text = preg_replace('/^\.{3}\s*/um', '', $text);

        return trim($text);
    }

    private function detectCurrency(string $code): string
    {
        $code = trim($code);

        $currency = self::CURRENCY_MAP[$code] ?? null;

        if ($currency === null) {
            Log::warning('ChipMongPaymentService: unknown currency code', [
                'code' => $code,
            ]);

            return 'KHR';
        }

        return $currency;
    }

    private function normalizePaymentMethod(string $bank): string
    {
        $bank = trim($bank);

        if ($bank === '') {
            return 'KHQR';
        }

        return "{$bank} KHQR";
    }

    private function parseDate(string $raw): Carbon
    {
        $raw = preg_replace('/\s+/', ' ', trim($raw));

        try {
            return Carbon::createFromFormat('M j, Y h:i A', $raw);
        } catch (\Throwable $e) {
            Log::warning('ChipMong date parse with format failed', [
                'raw' => $raw,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            Log::warning('ChipMong date parse fallback failed', [
                'raw' => $raw,
                'error' => $e->getMessage(),
            ]);
        }

        return now();
    }

    private function incrementPaymentUsed(?string $subscriptionId): void
    {
        if (! $subscriptionId) {
            Log::warning('Cannot increment payment_used: subscription_id is null');

            return;
        }

        DB::transaction(function () use ($subscriptionId) {
            $subscription = UserSubscription::query()
                ->where('userSubscriptionsID', $subscriptionId)
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                Log::warning('Cannot increment payment_used: subscription not found', [
                    'subscription_id' => $subscriptionId,
                ]);

                return;
            }

            $subscription->increment('payment_used');

            Log::info('payment_used incremented', [
                'subscription_id' => $subscriptionId,
                'payment_used' => $subscription->fresh()?->payment_used,
            ]);
        });
    }
}