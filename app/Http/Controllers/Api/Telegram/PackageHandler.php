<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Helpers\KhmerDateFormatter;
use App\Models\Package;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\Khqr\BakongService;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PackageHandler
{
    public function __construct(
        protected TelegramBotService $telegram,
        protected BakongService $payments,
    ) {}

    private function escapeMarkdown(?string $text): string
    {
        $text = (string) $text;

        return preg_replace('/([_*`\[])/', '\\\\$1', $text) ?? $text;
    }

    private function cycleLabel(?string $billingCycle): string
    {
        return match ($billingCycle) {
            'monthly'  => '១ ខែ',
            'yearly'   => '១ ឆ្នាំ',
            'lifetime' => 'អចិន្ត្រៃយ៍',
            default    => (string) $billingCycle,
        };
    }

    private function formatPrice(float|int|string|null $price): string
    {
        return number_format((float) $price, 2);
    }

    private function normalizePackageId(string $packageId): string
    {
        if (str_starts_with($packageId, 'pkg_buy_')) {
            return substr($packageId, strlen('pkg_buy_'));
        }

        return $packageId;
    }

    /**
     * Current active subscription for a Telegram chat/user id.
     * Returns null when the user or subscription doesn't exist.
     */
    private function activeSubscriptionForChat(string $chatId): ?UserSubscription
    {
        $user = User::where('telegram_id', (int) $chatId)->first(); // ← ADJUST column name if different

        if (! $user) {
            return null;
        }

        return UserSubscription::activeFor((string) $user->uuid);
    }

    public function showPackages(string $chatId, string $chatType): JsonResponse
    {
        if ($chatType !== 'private') {
            $this->telegram->sendMessage(
                $chatId,
                '🔒 សូមបើក Bot ក្នុង Private Chat ដើម្បីមើលកញ្ចប់សេវាកម្ម។'
            );

            return response()->json(['ok' => true]);
        }

        $lockKey = "pkg_show_{$chatId}";

        if (Cache::has($lockKey)) {
            return response()->json(['ok' => true]);
        }

        Cache::put($lockKey, true, now()->addSeconds(3));

        $packages = Package::query()
            ->orderBy('price')
            ->get();

        if ($packages->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                '❌ គ្មានកញ្ចប់បច្ចុប្បន្ន។ សូមទាក់ទង Admin។'
            );

            return response()->json(['ok' => true]);
        }

        // ── Header + current subscription summary ────────────────────────
        $headerLines = [
            "🆕 *កញ្ចប់សេវាកម្មទាំងអស់*",
            "ជ្រើសរើសកញ្ចប់ដែលអ្នកចង់បាន៖",
        ];

        $currentSub = $this->activeSubscriptionForChat($chatId);

        if ($currentSub) {
            $remaining = $currentSub->remainingPayments();

            $remainingLabel = $remaining === null
                ? '∞'
                : KhmerDateFormatter::formatNumber($remaining);

            $pkgName = $this->escapeMarkdown($currentSub->package?->name ?? '');

            $headerLines[] = '';
            $headerLines[] = "📌 *កញ្ចប់បច្ចុប្បន្នរបស់អ្នក:* {$pkgName}";
            $headerLines[] = "💳 ការទូទាត់នៅសល់: *{$remainingLabel}*";

            $headerLines[] = $currentSub->isLifetime()
                ? '📅 សុពលភាព: អចិន្ត្រៃយ៍'
                : '📅 ផុតកំណត់: ' . KhmerDateFormatter::formatDate($currentSub->ends_at);

            if ($remaining !== null && $remaining > 0) {
                $headerLines[] = "➕ ទិញកញ្ចប់ថ្មី ការទូទាត់នៅសល់ *{$remainingLabel}* នឹងបូកបញ្ចូលទៅកញ្ចប់ថ្មី។";
            }
        }

        $this->telegram->sendMessage(
            $chatId,
            implode("\n", $headerLines),
            ['parse_mode' => 'Markdown']
        );

        // Remaining quota used for the per-package total preview
        $carryOver = $currentSub?->remainingPayments() ?? 0;

        foreach ($packages as $i => $pkg) {
            $num = $i + 1;

            $status = $pkg->status === 'active' ? '✅' : '🔴';
            $name = $this->escapeMarkdown($pkg->name);
            $price = $this->formatPrice($pkg->price);
            $cycle = $this->cycleLabel($pkg->billing_cycle);

            $groups = method_exists($pkg, 'isUnlimitedGroups') && $pkg->isUnlimitedGroups()
                ? '∞'
                : (string) $pkg->group_limit;

            $payments = method_exists($pkg, 'isUnlimitedPayments') && $pkg->isUnlimitedPayments()
                ? '∞'
                : (string) $pkg->payment_limit;

            $lines = [
                "{$status} *{$num}. {$name}*",
                "💰 តម្លៃ: *{$price} USD*",
                "📅 រយៈពេល: {$cycle}",
                "👥 ក្រុម: {$groups}",
                "💳 ការទូទាត់: {$payments}",
            ];

            // ── Carry-over preview: base + remaining = total ─────────────
            $isUnlimited = method_exists($pkg, 'isUnlimitedPayments') && $pkg->isUnlimitedPayments();

            if (! $isUnlimited && $carryOver > 0) {
                $total = (int) $pkg->payment_limit + $carryOver;

                $totalKh = KhmerDateFormatter::formatNumber($total);
                $baseKh  = KhmerDateFormatter::formatNumber((int) $pkg->payment_limit);
                $carryKh = KhmerDateFormatter::formatNumber($carryOver);

                $lines[] = "🧮 សរុបក្រោយទិញ: *{$totalKh}* ({$baseKh} + {$carryKh} នៅសល់)";
            }

            $text = implode("\n", $lines);

            $extra = [
                'parse_mode' => 'Markdown',
            ];

            if ($pkg->status === 'active') {
                $extra['reply_markup'] = json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' => "🛒 ទិញ {$pkg->name}",
                                'callback_data' => 'pkg_buy_' . $pkg->packagesID,
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE);
            }

            $this->telegram->sendMessage($chatId, $text, $extra);
        }

        $supportRaw = (string) config('services.telegram.support_username');
        $support = $this->escapeMarkdown($supportRaw);

        if ($supportRaw !== '') {
            $this->telegram->sendMessage(
                $chatId,
                "📩 មានសំណួរ? ទំនាក់ទំនង @{$support}",
                ['parse_mode' => 'Markdown']
            );
        }

        return response()->json(['ok' => true]);
    }

    public function handleBuyCallback(
        string $chatId,
        int $messageId,
        string $packageId,
        string $requestedBy,
        string $chatType
    ): void {
        $packageId = $this->normalizePackageId($packageId);

        if ($chatType !== 'private') {
            $this->telegram->editMessage(
                $chatId,
                $messageId,
                '🔒 សូមបើក Bot ក្នុង Private Chat ដើម្បីទិញកញ្ចប់។'
            );

            return;
        }

        $lockKey = "pkg_buy_{$chatId}_{$packageId}";

        if (Cache::has($lockKey)) {
            return;
        }

        Cache::put($lockKey, true, now()->addSeconds(5));

        $package = Package::where('packagesID', $packageId)->first();

        if (! $package) {
            $this->telegram->editMessage(
                $chatId,
                $messageId,
                '❌ កញ្ចប់នេះមិនមានទេ។'
            );

            return;
        }

        if ($package->status !== 'active') {
            $this->telegram->editMessage(
                $chatId,
                $messageId,
                '🔴 កញ្ចប់នេះមិនអាចទិញបានទេ។ សូមជ្រើសរើសកញ្ចប់ផ្សេង។'
            );

            return;
        }

        try {
            $payment = $this->payments->createCheckout(
                package: $package,
                telegramUserId: (int) $chatId,
                requestedBy: $requestedBy,
            );

            $payUrl = $this->payments->checkoutUrl($payment);

            if (empty($payUrl)) {
                throw new \RuntimeException('Checkout URL is empty.');
            }

            if (! str_starts_with($payUrl, 'https://')) {
                Log::warning('Telegram payment URL is not HTTPS', [
                    'pay_url' => $payUrl,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Bakong checkout failed', [
                'package_id' => $packageId,
                'chat_id' => $chatId,
                'requested_by' => $requestedBy,
                'error' => $e->getMessage(),
            ]);

            $this->telegram->editMessage(
                $chatId,
                $messageId,
                implode("\n", [
                    '❌ មិនអាចបង្កើតការទូទាត់បានទេ។',
                    'សូមព្យាយាមម្ដងទៀត ឬទំនាក់ទំនង Admin។',
                ])
            );

            return;
        }

        $supportRaw = (string) config('services.telegram.support_username');

        $name = $this->escapeMarkdown($package->name);
        $buyer = $this->escapeMarkdown($requestedBy);
        $price = $this->formatPrice($package->price);
        $cycle = $this->cycleLabel($package->billing_cycle);

        $lines = [
            "🛒 *បញ្ជាក់ការទិញ*",
            "─────────────────────",
            "📦 កញ្ចប់: *{$name}*",
            "💰 តម្លៃ: *{$price} USD*",
            "📅 រយៈពេល: {$cycle}",
            "👤 អ្នកទិញ: {$buyer}",
        ];

        // ── Carry-over preview on the confirmation message ───────────────
        $currentSub = $this->activeSubscriptionForChat($chatId);
        $carryOver  = $currentSub?->remainingPayments() ?? 0;

        $isUnlimited = method_exists($package, 'isUnlimitedPayments')
            && $package->isUnlimitedPayments();

        if (! $isUnlimited && $carryOver > 0) {
            $total = (int) $package->payment_limit + $carryOver;

            $totalKh = KhmerDateFormatter::formatNumber($total);
            $baseKh  = KhmerDateFormatter::formatNumber((int) $package->payment_limit);
            $carryKh = KhmerDateFormatter::formatNumber($carryOver);

            $lines[] = "➕ នៅសល់ពីកញ្ចប់ចាស់: {$carryKh}";
            $lines[] = "🧮 សរុបក្រោយបង់ប្រាក់: *{$totalKh}* ({$baseKh} + {$carryKh})";
        }

        if (! empty($payment->external_transaction_id)) {
            $invoice = $this->escapeMarkdown($payment->external_transaction_id);
            $lines[] = "🧾 លេខវិក្កយបត្រ: `{$invoice}`";
        }

        $lines[] = "─────────────────────";
        $lines[] = "👇 ចុចប៊ូតុង *បង់ប្រាក់* ដើម្បីបើកទំព័រ KHQR។";
        $lines[] = "⏳ QR មានសុពលភាព ១៥ នាទី។";

        $text = implode("\n", $lines);

        $keyboard = [
            [
                [
                    'text' => '💳 បង់ប្រាក់ឥឡូវនេះ',
                    'url'  => $payUrl,
                ],
            ],
        ];

        if ($supportRaw !== '') {
            $keyboard[] = [
                [
                    'text' => '💬 ទំនាក់ទំនង Admin',
                    'url'  => "https://t.me/{$supportRaw}",
                ],
            ];
        }

        $this->telegram->editMessage(
            $chatId,
            $messageId,
            $text,
            $keyboard
        );
    }
}