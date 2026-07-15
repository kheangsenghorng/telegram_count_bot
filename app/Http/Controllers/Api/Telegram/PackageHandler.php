<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Helpers\KhmerDateFormatter;
use App\Models\Package;
use App\Models\PackageTransaction;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\PayWay\AbaCheckoutService;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PackageHandler
{
    /*
    |--------------------------------------------------------------------------
    | Cache TTLs (seconds)
    |--------------------------------------------------------------------------
    */
    private const TTL_PACKAGES     = 3600;   // 1 hour — package list (invalidated on admin edit)
    private const TTL_PACKAGE_ROW  = 300;    // 5 min  — single package on the buy path (fresher for status changes)
    private const TTL_USER_MAP     = 86400;  // 1 day  — telegram_id → user uuid (never really changes)
    private const TTL_SUBSCRIPTION = 300;    // 5 min  — active subscription

    public function __construct(
        protected TelegramBotService $telegram,
        protected AbaCheckoutService $payments,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Cache keys + invalidation (call these from admin panel / activation)
    |--------------------------------------------------------------------------
    */

    public static function packagesListKey(): string
    {
        return 'pkg:list';
    }

    public static function packageKey(string $packagesID): string
    {
        return "pkg:row:{$packagesID}";
    }

    public static function userMapKey(string $chatId): string
    {
        return "pkg:usermap:{$chatId}";
    }

    public static function subscriptionKey(string $userUuid): string
    {
        return "pkg:sub:{$userUuid}";
    }

    /**
     * Call when a package is created/edited/deleted in the admin panel.
     */
    public static function invalidatePackages(?string $packagesID = null): void
    {
        Cache::forget(self::packagesListKey());

        if ($packagesID !== null) {
            Cache::forget(self::packageKey($packagesID));
        }
    }

    /**
     * Call from PaymentConfirmationService::activatePackage() after a
     * subscription is created/updated, so the header + carry-over preview
     * refresh instantly instead of after 5 minutes.
     */
    public static function invalidateSubscription(string $userUuid): void
    {
        Cache::forget(self::subscriptionKey($userUuid));
    }

    private function escapeMarkdown(?string $text): string
    {
        $text = (string) $text;

        return preg_replace('/([_*`\[])/', '\\\\$1', $text) ?? $text;
    }

    private function cycleLabel(?string $billingCycle): string
    {
        return match ($billingCycle) {
            'weekly'   => '១ សប្តាហ៍',
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

    /*
    |--------------------------------------------------------------------------
    | Cached lookups
    |--------------------------------------------------------------------------
    */

    private function getPackages()
    {
        return Cache::remember(
            self::packagesListKey(),
            self::TTL_PACKAGES,
            fn () => Package::query()
                ->orderBy('price')
                ->get()
        );
    }

    private function getPackage(string $packagesID): ?Package
    {
        return Cache::remember(
            self::packageKey($packagesID),
            self::TTL_PACKAGE_ROW,
            fn () => Package::where('packagesID', $packagesID)->first()
        );
    }

    /**
     * Current active subscription for a Telegram chat/user id.
     * Two-level cache: telegram_id → uuid (1 day), uuid → subscription (5 min).
     * Returns null when the user or subscription doesn't exist.
     */
    private function activeSubscriptionForChat(string $chatId): ?UserSubscription
    {
        $userUuid = Cache::remember(
            self::userMapKey($chatId),
            self::TTL_USER_MAP,
            function () use ($chatId): ?string {
                $user = User::where('telegram_id', (int) $chatId)->first(); // ← ADJUST column name if different

                return $user ? (string) $user->uuid : null;
            }
        );

        if ($userUuid === null) {
            // Don't let "user not found" stick for a day — a brand-new user
            // would otherwise be invisible until the key expires.
            Cache::forget(self::userMapKey($chatId));

            return null;
        }

        return Cache::remember(
            self::subscriptionKey($userUuid),
            self::TTL_SUBSCRIPTION,
            fn () => UserSubscription::activeFor($userUuid)
        );
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

        // Atomic lock — Cache::add returns false if the key already exists,
        // so a double-tap can never slip through the has()/put() race window.
        $lockKey = "pkg_show_{$chatId}";

        if (! Cache::add($lockKey, true, now()->addSeconds(3))) {
            return response()->json(['ok' => true]);
        }

        $packages = $this->getPackages();

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
            $name   = $this->escapeMarkdown($pkg->name);
            $price  = $this->formatPrice($pkg->price);
            $cycle  = $this->cycleLabel($pkg->billing_cycle);

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
                                'text'          => "🛒 ទិញ {$pkg->name}",
                                'callback_data' => 'pkg_buy_' . $pkg->packagesID,
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE);
            }

            $this->telegram->sendMessage($chatId, $text, $extra);

            // Gentle pacing — avoids tripping Telegram's per-chat rate limit
            // when the package list grows.
            usleep(50_000);
        }

        $supportRaw = (string) config('services.telegram.support_username');
        $support    = $this->escapeMarkdown($supportRaw);

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
 
        // Atomic lock — see showPackages()
        $lockKey = "pkg_buy_{$chatId}_{$packageId}";
 
        if (! Cache::add($lockKey, true, now()->addSeconds(5))) {
            return;
        }
 
        $package = $this->getPackage($packageId);
 
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
 
        // Show "typing…" while ABA creates the payment link (can take a moment)
        $this->telegram->sendChatAction($chatId);
 
        try {
            // ── Reuse an existing pending, unexpired link for this
            //    chat + package. Prevents duplicate ABA payment links
            //    when the user taps Buy twice. The stored checkout_url
            //    is always used — never regenerated.
            $payment = PackageTransaction::query()
                ->where('telegram_chat_id', (string) $chatId)
                ->where('package_id', $package->packagesID)
                ->where('gateway', 'aba_payway')
                ->where('status', 'pending')
                ->where('expires_at', '>', now()->addMinutes(2)) // ≥2 min left, or make a fresh one
                ->whereNotNull('checkout_url')
                ->latest('created_at')
                ->first();
 
            if (! $payment) {
                // WRITE path — always hits DB/ABA directly, never cached.
                $payment = $this->payments->createCheckout(
                    package: $package,
                    telegramUserId: $chatId,
                    requestedBy: $requestedBy,
                    telegramChatId: $chatId,
                    telegramMessageId: $messageId,
                );
            }
 
            $payment->forceFill([
                'telegram_chat_id'    => (string) $chatId,
                'telegram_message_id' => $messageId,
            ])->save();
 
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
            // Let the user retry immediately — don't hold the lock
            // for the remaining seconds after a failure.
            Cache::forget($lockKey);
 
            Log::error('ABA PayWay checkout failed', [
                'package_id'   => $packageId,
                'chat_id'      => $chatId,
                'requested_by' => $requestedBy,
                'error'        => $e->getMessage(),
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
 
        $name  = $this->escapeMarkdown($package->name);
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
 
        if (! empty($payment->merchant_ref_no)) {
            $invoice = $this->escapeMarkdown($payment->merchant_ref_no);
            $lines[] = "🧾 លេខវិក្កយបត្រ: `{$invoice}`";
        }
 
        // ── Remaining validity: from the stored expires_at when reusing
        //    a link, otherwise the configured lifetime.
        $minutesLeft = $payment->expires_at
            ? max(1, (int) now()->diffInMinutes($payment->expires_at, false))
            : (int) config('payway.payment_link_lifetime_minutes', 30);
 
        $ttlKh = KhmerDateFormatter::formatNumber($minutesLeft);
 
        $lines[] = "─────────────────────";
        $lines[] = "👇 ចុចប៊ូតុង *បង់ប្រាក់* ដើម្បីបើកទំព័រ ABA PayWay។";
        $lines[] = "⏳ តំណបង់ប្រាក់មានសុពលភាព {$ttlKh} នាទី។";
 
        $text = implode("\n", $lines);
 
        $keyboard = [
            [
                [
                    'text' => '💳 បង់ប្រាក់តាម ABA',
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