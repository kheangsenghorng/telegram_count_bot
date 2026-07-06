<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\KhmerDateFormatter;
use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AbaPaymentService
{
    private const CURRENCY_MAP = [
        '៛'  => 'KHR',
        '$'  => 'USD',
        '＄' => 'USD',
    ];

    /**
     * English ABA / PayWay message.
     *
     * Supports:
     * $2.50 paid by Sreyla Botum (*948) on Jun 28, 06:11 PM via ABA KHQR (Wing Bank (Cambodia) Plc) at CHEN KHEANG. Trx. ID: 178264507253336, APV: 437425.
     * ៛4,000 paid by BORN SOPHEAK (*021) on Jun 28, 06:13 PM via ABA PAY at CHEN KHEANG. Trx. ID: 178264518833769, APV: 350810.
     * ៛28,000 paid by CHHOM SREYNOU (*601) on Jul 04, 05:04 PM via ABA PAY at CHEN KHEANG. Remark: ដោះគោ. Trx. ID: 178315949768571, APV: 326963.
     * ៛11,000 paid by Say Makara and Touch Chansorany (*001) on Jul 06, 05:48 PM via ABA KHQR (KB PRASAC Bank Plc) at CHEN KHEANG. Trx. ID: 178333489262170, APV: 348252.
     */
    private const PATTERN_EN = '/
        (?P<currency_symbol>[៛\$＄])
        \s*
        (?P<amount>[\d,]+(?:\.\d+)?)

        \s+paid\s+by\s+
        (?P<payer_name>.+?)
        \s*
        \((?P<payer_account>[^)]+)\)

        \s+on\s+
        (?P<date>
            [A-Za-z]{3,9}
            \s+
            \d{1,2}
            ,\s*
            \d{1,2}:\d{2}
            \s*
            (?:AM|PM)
        )

        \s+via\s+
        (?P<method>
            ABA
            \s+
            (?:PAY|KHQR|MOBILE|Mobile|Transfer|.+?)
        )

        (?:\s+\((?P<bank_code>.+?)\))?

        \s+at\s+
        (?P<merchant>.+?)

        (?:\.\s*Remark:\s*(?P<remark>.+?))?

        \.\s*Trx\.\s*ID:\s*
        (?P<trx_id>\d+)

        ,\s*APV:\s*
        (?P<apv>\d+)

        \.?
    /uix';

    /**
     * Khmer ABA / PayWay message.
     *
     * Example:
     * ៛2,000 ត្រូវបានបង់ដោយ Loek Sreymom (*016) នៅថ្ងៃទី 6 ខែកក្កដា ឆ្នាំ 2026 ម៉ោង 15:33 តាម ABA KHQR (KB PRASAC Bank Plc) នៅ MUT SOPHEAP។ លេខប្រតិបត្តិការ: 178332678565175។ APV: 914741។
     */
    private const PATTERN_KH = '/
        (?P<currency_symbol>[៛\$＄])
        \s*
        (?P<amount>[\d,]+(?:\.\d+)?)

        \s+ត្រូវបានបង់ដោយ\s+
        (?P<payer_name>.+?)
        \s*
        \((?P<payer_account>[^)]+)\)

        \s+នៅថ្ងៃទី\s*
        (?P<kh_day>\d{1,2})

        \s+ខែ
        (?P<kh_month>\S+)

        \s+ឆ្នាំ\s*
        (?P<kh_year>\d{4})

        \s+ម៉ោង\s*
        (?P<kh_time>\d{1,2}:\d{2})

        \s+តាម\s+
        (?P<method>
            ABA
            \s+
            (?:PAY|KHQR|MOBILE|Mobile|Transfer|.+?)
        )

        (?:\s+\((?P<bank_code>.+?)\))?

        \s+នៅ\s+
        (?P<merchant>.+?)

        ។\s*
        (?:សម្គាល់:\s*(?P<remark>[^។]*?)\s*។\s*)?

        លេខប្រតិបត្តិការ:\s*
        (?P<trx_id>\d+)

        ។\s*APV:\s*
        (?P<apv>\d+)

        ។?
    /uix';

    public function __construct(
        protected TelegramBotService $telegram
    ) {}

    public function process(string $rawText, string|int $telegramChatId): array
    {
        $telegramChatId = (string) $telegramChatId;
        $cleanText = $this->cleanText($rawText);

        if ($this->isOwnConfirmationMessage($cleanText)) {
            Log::info('ABA payment ignored: own confirmation message', [
                'chat_id' => $telegramChatId,
            ]);

            return $this->emptyResult(
                parsed: false,
                skipped: true,
                reason: 'own_confirmation_message'
            );
        }

        $group = $this->findGroup($telegramChatId);

        if (! $this->isGroupReady($group)) {
            Log::notice('ABA payment ignored: group not fully linked, nothing stored', [
                'chat_id' => $telegramChatId,
                'group_found' => (bool) $group,
                'user_id' => $group?->user_id,
                'subscription_id' => $group?->subscription_id,
                'telegram_group_id' => $group?->telegramGroupsID,
            ]);

            return $this->emptyResult(
                parsed: false,
                group: $group,
                skipped: true,
                reason: 'group_not_linked'
            );
        }

        $matches = $this->parseMessages($cleanText, $telegramChatId);

        if (empty($matches)) {
            Log::warning('ABA parse failed', [
                'chat_id' => $telegramChatId,
                'original' => $rawText,
                'clean' => $cleanText,
            ]);

            return $this->emptyResult(
                parsed: false,
                group: $group,
                skipped: true,
                reason: 'parse_failed'
            );
        }

        $savedPayments = [];
        $successCount = 0;
        $duplicateCount = 0;

        foreach ($matches as $match) {
            $data = $this->normalizeMatch($match, $rawText);

            $payment = null;
            $isDuplicate = false;

            try {
                DB::transaction(function () use (
                    &$payment,
                    &$isDuplicate,
                    $group,
                    $data
                ): void {
                    $existing = TelegramPayment::query()
                        ->where('trx_id', $data['trx_id'])
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        $payment = $existing;
                        $isDuplicate = true;

                        return;
                    }

                    $payload = [
                        'user_id' => $group->user_id,
                        'subscription_id' => $group->subscription_id,
                        'telegram_group_id' => $group->telegramGroupsID,

                        'currency' => $data['currency'],
                        'amount' => $data['amount'],
                        'payer_name' => $data['payer_name'],
                        'payer_account' => $data['payer_account'],
                        'merchant_name' => $data['merchant'],
                        'payment_method' => $data['method'],
                        'bank_code' => $data['bank_code'],

                        'trx_id' => $data['trx_id'],
                        'apv' => $data['apv'],

                        'payment_date' => $data['payment_date'],
                        'report_date' => $data['payment_date']->toDateString(),
                        'report_month' => $data['payment_date']->month,
                        'report_year' => $data['payment_date']->year,

                        'raw_message' => $data['raw_message'],
                        'status' => 'success',
                        'parsed_successfully' => true,
                        'is_duplicate' => false,
                    ];

                    if (Schema::hasColumn('telegram_payments', 'remark')) {
                        $payload['remark'] = $data['remark'];
                    }

                    $payment = TelegramPayment::create($payload);

                    /*
                    |--------------------------------------------------------------------------
                    | Consume subscription payment quota
                    |--------------------------------------------------------------------------
                    | Only new payments reach this line.
                    | Duplicate payments return earlier and will not increase payment_used.
                    */
                    $subscription = UserSubscription::query()
                        ->where('userSubscriptionsID', $group->subscription_id)
                        ->lockForUpdate()
                        ->first();
                    
                    if ($subscription && ! $subscription->consumePayment()) {
                        Log::warning('Payment saved but subscription quota is full', [
                            'subscription_id' => $group->subscription_id,
                            'trx_id' => $data['trx_id'],
                        ]);
                    }
                });
            } catch (UniqueConstraintViolationException) {
                Log::info('ABA payment duplicate caught by unique trx_id', [
                    'trx_id' => $data['trx_id'],
                    'chat_id' => $telegramChatId,
                ]);

                $isDuplicate = true;

                $payment = TelegramPayment::query()
                    ->where('trx_id', $data['trx_id'])
                    ->first();
            } catch (\Throwable $e) {
                Log::error('ABA payment save failed', [
                    'trx_id' => $data['trx_id'],
                    'chat_id' => $telegramChatId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($isDuplicate) {
                $duplicateCount++;
            } else {
                $successCount++;

                $this->sendPaymentAlert(
                    telegramChatId: $telegramChatId,
                    amount: $data['amount'],
                    currency: $data['currency'],
                    payerName: $data['payer_name'],
                    payerAccount: $data['payer_account'],
                    merchant: $data['merchant'],
                    method: $data['method'],
                    bankCode: $data['bank_code'],
                    remark: $data['remark'],
                    paymentDate: $data['payment_date'],
                    trxId: $data['trx_id'],
                    apv: $data['apv'],
                );
            }

            if ($payment) {
                $savedPayments[] = $payment;
            }

            Log::info('ABA payment processed', [
                'trx_id' => $data['trx_id'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'payer' => $data['payer_name'],
                'merchant' => $data['merchant'],
                'method' => $data['method'],
                'bank_code' => $data['bank_code'],
                'remark' => $data['remark'],
                'is_duplicate' => $isDuplicate,
            ]);
        }

        if ($successCount > 0) {
            $group->update([
                'last_payment_at' => now(),
            ]);
        }

        return [
            'parsed' => $successCount > 0 || $duplicateCount > 0,
            'group' => $group,
            'payment' => $savedPayments[0] ?? null,
            'payments' => $savedPayments,
            'currency' => $savedPayments[0]?->currency ?? null,
            'is_duplicate' => $duplicateCount > 0 && $successCount === 0,
            'count' => $successCount,
            'success_count' => $successCount,
            'duplicate_count' => $duplicateCount,
            'skipped' => false,
        ];
    }

    private function parseMessages(string $cleanText, string $telegramChatId): array
    {
        preg_match_all(self::PATTERN_EN, $cleanText, $enMatches, PREG_SET_ORDER);
        preg_match_all(self::PATTERN_KH, $cleanText, $khMatches, PREG_SET_ORDER);

        $matches = array_merge($enMatches ?: [], $khMatches ?: []);

        return $this->uniqueMatchesByTrxId($matches, $telegramChatId);
    }

    private function normalizeMatch(array $match, string $rawText): array
    {
        $currency = $this->detectCurrency((string) ($match['currency_symbol'] ?? ''));

        $paymentDate = ! empty($match['kh_day'])
            ? $this->parseKhmerDate($match)
            : $this->parseEnglishDate((string) $match['date']);

        return [
            'currency' => $currency,
            'amount' => (float) str_replace(',', '', (string) $match['amount']),
            'payer_name' => $this->cleanValue((string) $match['payer_name']),
            'payer_account' => $this->cleanValue((string) $match['payer_account']),
            'merchant' => $this->cleanValue((string) $match['merchant']),
            'method' => $this->cleanValue((string) $match['method']),
            'bank_code' => ! empty($match['bank_code'])
                ? $this->cleanValue((string) $match['bank_code'])
                : null,
            'remark' => ! empty($match['remark'])
                ? $this->cleanValue((string) $match['remark'])
                : null,
            'trx_id' => $this->cleanValue((string) $match['trx_id']),
            'apv' => $this->cleanValue((string) $match['apv']),
            'payment_date' => $paymentDate,
            'raw_message' => $rawText,
        ];
    }

    private function sendPaymentAlert(
        string $telegramChatId,
        float $amount,
        string $currency,
        string $payerName,
        string $payerAccount,
        string $merchant,
        string $method,
        ?string $bankCode,
        ?string $remark,
        Carbon $paymentDate,
        string $trxId,
        string $apv,
    ): void {
        $symbol = $currency === 'KHR' ? '៛' : '$';
        $decimals = $currency === 'KHR' ? 0 : 2;

        $formattedAmount = number_format($amount, $decimals);
        $displayMethod = $bankCode ? "{$method} ({$bankCode})" : $method;

        $payerName = $this->safeMarkdown($payerName);
        $payerAccount = $this->safeMarkdown($payerAccount);
        $merchant = $this->safeMarkdown($merchant);
        $displayMethod = $this->safeMarkdown($displayMethod);
        $remark = $remark !== null ? $this->safeMarkdown($remark) : null;
        $trxId = $this->safeMarkdown($trxId);
        $apv = $this->safeMarkdown($apv);

        $lines = [
            '💳 *ABA Payment Received*',
            '━━━━━━━━━━━━━━━━━━',
            "💰 *Amount:*    `{$symbol}{$formattedAmount}`",
            "👤 *Payer:*     `{$payerName} ({$payerAccount})`",
            "🏪 *Merchant:*  `{$merchant}`",
            "📲 *Method:*    `{$displayMethod}`",
        ];

        if ($remark !== null && $remark !== '') {
            $lines[] = "📝 *Remark:*    `{$remark}`";
        }

        $lines = array_merge($lines, [
            "📅 *Date:*      `{$paymentDate->format('M j, Y h:i A')}`",
            "🔖 *Trx ID:*    `{$trxId}`",
            "✅ *APV:*       `{$apv}`",
            '━━━━━━━━━━━━━━━━━━',
            '✅ Payment confirmed',
        ]);

        $this->telegram->sendMarkdown($telegramChatId, implode("\n", $lines));
    }

    private function findGroup(string $chatId): ?TelegramGroup
    {
        $group = TelegramGroup::query()
            ->where('group_id', $chatId)
            ->where('status', 'connected')
            ->latest()
            ->first();

        if (! $group) {
            Log::notice('AbaPaymentService: no connected group for chat', [
                'chat_id' => $chatId,
            ]);
        }

        return $group;
    }

    private function isGroupReady(?TelegramGroup $group): bool
    {
        return $group !== null
            && ! empty($group->user_id)
            && ! empty($group->subscription_id)
            && ! empty($group->telegramGroupsID);
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove invisible/special Telegram spaces.
        $text = str_replace([
            "\u{00A0}",
            "\u{200B}",
            "\u{200C}",
            "\u{200D}",
            "\u{FEFF}",
        ], ' ', $text);

        // Remove Telegram copied message header.
        // Example:
        // PayWay by ABA, [6 Jul 2026 at 5:48:00 in the afternoon]:
        $text = preg_replace('/^.+?,\s*\[.*?\]:\s*/us', '', $text) ?? $text;

        // Remove username prefix.
        $text = preg_replace('/^@\S+\s+/u', '', $text) ?? $text;

        // Remove bracket prefix.
        $text = preg_replace('/^\[.*?\]:\s*/us', '', $text) ?? $text;

        // Remove leading dots.
        $text = preg_replace('/^\.{3}\s*/u', '', $text) ?? $text;

        // Normalize spaces.
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{2,}/u", "\n", $text) ?? $text;

        return trim($text);
    }

    private function cleanValue(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function uniqueMatchesByTrxId(array $matches, string $telegramChatId): array
    {
        $uniqueMatches = [];
        $seenTrxIds = [];

        foreach ($matches as $match) {
            $trxId = trim((string) ($match['trx_id'] ?? ''));

            if ($trxId === '') {
                continue;
            }

            if (isset($seenTrxIds[$trxId])) {
                Log::info('Duplicate ABA payment inside same message ignored', [
                    'trx_id' => $trxId,
                    'chat_id' => $telegramChatId,
                ]);

                continue;
            }

            $seenTrxIds[$trxId] = true;
            $uniqueMatches[] = $match;
        }

        return $uniqueMatches;
    }

    private function isOwnConfirmationMessage(string $text): bool
    {
        return str_contains($text, 'ABA Payment Received')
            || str_contains($text, 'Payment confirmed')
            || str_contains($text, '💳 ABA Payment Received')
            || str_contains($text, '✅ Payment confirmed');
    }

    private function detectCurrency(string $symbol): string
    {
        $symbol = trim($symbol);

        if (isset(self::CURRENCY_MAP[$symbol])) {
            return self::CURRENCY_MAP[$symbol];
        }

        Log::warning('AbaPaymentService: unknown currency symbol', [
            'symbol' => $symbol,
        ]);

        return 'KHR';
    }

    private function parseEnglishDate(string $raw): Carbon
    {
        $raw = preg_replace('/\s+/', ' ', trim($raw)) ?? trim($raw);

        try {
            $now = now();

            $date = Carbon::createFromFormat('M j, g:i A', $raw)
                ->year($now->year);

            // ABA English message has no year.
            // If parsed date is in the future, it probably belongs to last year.
            if ($date->isAfter($now->copy()->addDay())) {
                $date->subYear();
            }

            return $date;
        } catch (\Throwable $e) {
            Log::warning('ABA English date parse failed', [
                'raw' => $raw,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            Log::warning('ABA English date parse fallback failed', [
                'raw' => $raw,
                'error' => $e->getMessage(),
            ]);
        }

        return now();
    }

    private function parseKhmerDate(array $match): Carbon
    {
        try {
            $month = KhmerDateFormatter::monthNumber((string) $match['kh_month']);

            if ($month === null) {
                Log::warning('ABA Khmer month not recognized', [
                    'raw' => $match['kh_month'],
                ]);

                return now();
            }

            [$hour, $minute] = explode(':', (string) $match['kh_time']);

            return Carbon::create(
                (int) $match['kh_year'],
                $month,
                (int) $match['kh_day'],
                (int) $hour,
                (int) $minute,
            );
        } catch (\Throwable $e) {
            Log::warning('ABA Khmer date parse failed', [
                'match' => $match,
                'error' => $e->getMessage(),
            ]);

            return now();
        }
    }

    private function safeMarkdown(string $value): string
    {
        // Your TelegramBotService appears to use normal Markdown.
        // Backticks can break inline code formatting, so remove them.
        return str_replace('`', "'", $value);
    }

    private function emptyResult(
        bool $parsed,
        ?TelegramGroup $group = null,
        bool $skipped = false,
        ?string $reason = null
    ): array {
        return [
            'parsed' => $parsed,
            'group' => $group,
            'payment' => null,
            'payments' => [],
            'currency' => null,
            'is_duplicate' => false,
            'count' => 0,
            'success_count' => 0,
            'duplicate_count' => 0,
            'skipped' => $skipped,
            'reason' => $reason,
        ];
    }
}