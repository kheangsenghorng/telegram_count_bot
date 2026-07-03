<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
     * ACLEDA to Chip Mong Pay KHR 6,500 is paid by ACLEDA Bank Plc. via KHQR for purchase 540abedd. From Keo Sorany, at CHEN KHEANG, date Jul 01, 2026 11:30 AM
     *
     * KHR 19,000 is paid by ACLEDA Bank Plc. via KHQR for purchase 81fda03b. From Oum Socheata, at CHEN KHEANG, date Jun 29, 2026 07:28 PM
     *
     * KHR 6,500 is paid by ACLEDA Bank Plc. via KHQR for purchase 540abedd. From Keo Sorany, at CHEN KHEANG, Merchant account, date Jul 1, 2026 11:30 AM
     */
    private const PATTERN = '/
        (?:ACLEDA\s+to\s+(?P<merchant>.+?)\s+)?
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
        (?P<location>.+?)
        (?:,\s+(?P<account_type>.+?))?
        ,\s+date\s+
        (?P<date>[A-Za-z]+\s+\d{1,2},\s*\d{4}\s+\d{1,2}:\d{2}\s*(?:AM|PM))
    /uix';

    public function __construct(
        protected TelegramBotService $telegram
    ) {}

    // -------------------------------------------------------------------------
    // Main entry point
    // -------------------------------------------------------------------------

    public function process(string $rawText, string $telegramChatId): array
    {
        $group = $this->findGroup($telegramChatId);

        $userId = $group?->user_id;
        $subscriptionId = $group?->subscription_id;
        $telegramGroupId = $group?->telegramGroupsID;

        $cleanText = $this->cleanText($rawText);

        preg_match_all(self::PATTERN, $cleanText, $matches, PREG_SET_ORDER);

        // ---------------------------------------------------------------------
        // Parse failed
        // ---------------------------------------------------------------------

        if (empty($matches)) {
            Log::warning('ChipMong (ACLEDA) parse failed', [
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
                    "⚠️ *ACLEDA/Chip Mong Payment Received — Parse Failed*\n\n"
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

        // ---------------------------------------------------------------------
        // Parse success: save all payments
        // ---------------------------------------------------------------------

        $savedPayments = [];
        $duplicateCount = 0;
        $successCount = 0;

        foreach ($matches as $match) {
            $currency = $this->detectCurrency($match['currency_code'] ?? '');
            $paymentDate = $this->parseDate($match['date']);
            $orderRef = trim($match['order_ref']);

            $amount = (float) str_replace(',', '', $match['amount']);
            $payerName = trim($match['payer_name']);
            $bank = trim($match['bank']);
            $merchant = ! empty($match['merchant']) ? trim($match['merchant']) : 'Chip Mong Pay';
            $location = trim($match['location']);
            $accountType = ! empty($match['account_type']) ? trim($match['account_type']) : null;

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
                    'payment_method' => 'ACLEDA KHQR',
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

    // -------------------------------------------------------------------------
    // Alert formatter
    // -------------------------------------------------------------------------

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
    ): void {
        $symbol = $currency === 'KHR' ? '៛' : '$';
        $decimals = $currency === 'KHR' ? 0 : 2;

        $formattedAmount = number_format($amount, $decimals);

        $lines = [
            "💳 *Chip Mong Payment Received (ACLEDA)*",
            "━━━━━━━━━━━━━━━━━━",
            "💰 *Amount:*    `{$symbol}{$formattedAmount}`",
            "👤 *Payer:*     `{$payerName}`",
            "🏪 *Merchant:*  `{$merchant}`",
            "🏦 *Bank:*      `{$bank}`",
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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

        $text = preg_replace('/^.+?,\s*\[.*?\]:\s*/us', '', $text);
        $text = preg_replace('/^@\S+\s+/u', '', $text);
        $text = preg_replace('/^\[.*?\]:\s*/us', '', $text);
        $text = preg_replace('/^\.{3}\s*/u', '', $text);

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