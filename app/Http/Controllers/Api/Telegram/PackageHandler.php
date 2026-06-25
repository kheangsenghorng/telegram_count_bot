<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Models\Package;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PackageHandler
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    public function showPackages(string $chatId, string $chatType): JsonResponse
    {
        // ── Private chat only ─────────────────────────────────────────────────
        if ($chatType !== 'private') {
            $this->telegram->sendMessage($chatId, '🔒 សូមបើក Bot ក្នុង Private Chat ដើម្បីមើលកញ្ចប់សេវាកម្ម។');
            return response()->json(['ok' => true]);
        }

        // ── Prevent duplicate calls within 3 seconds ──────────────────────────
        $lockKey = "pkg_show_{$chatId}";
        if (Cache::has($lockKey)) {
            return response()->json(['ok' => true]);
        }
        Cache::put($lockKey, true, now()->addSeconds(3));

        // ── Load packages ─────────────────────────────────────────────────────
        $packages = Package::orderBy('price')->get();

        if ($packages->isEmpty()) {
            $this->telegram->sendMessage($chatId, '❌ គ្មានកញ្ចប់បច្ចុប្បន្ន។ សូមទាក់ទង Admin។');
            return response()->json(['ok' => true]);
        }

        // ── Header ────────────────────────────────────────────────────────────
        $this->telegram->sendMessage($chatId, "🆕 *កញ្ចប់សេវាកម្មទាំងអស់*\nជ្រើសរើសកញ្ចប់ដែលអ្នកចង់បាន៖");

        // ── Each package as separate message with buy button ──────────────────
        foreach ($packages as $i => $pkg) {
            $num      = $i + 1;
            $status   = $pkg->status === 'active' ? '✅' : '🔴';
            $price    = number_format((float) $pkg->price, 2);  // ← fixed
            $cycle    = match ($pkg->billing_cycle) {
                'monthly'  => '១ ខែ',
                'yearly'   => '១ ឆ្នាំ',
                'lifetime' => 'អចិន្ត្រៃយ៍',
                default    => $pkg->billing_cycle,
            };
            $groups   = $pkg->isUnlimitedGroups()   ? '∞' : $pkg->group_limit;
            $payments = $pkg->isUnlimitedPayments() ? '∞' : $pkg->payment_limit;

            $lines   = [];
            $lines[] = "{$status} *{$num}. {$pkg->name}*";
            $lines[] = "💰 តម្លៃ: *{$price} USD*";
            $lines[] = "📅 រយៈពេល: {$cycle}";
            $lines[] = "👥 ក្រុម: {$groups}";
            $lines[] = "💳 ការទូទាត់: {$payments}";

            $extra = [];
            if ($pkg->status === 'active') {
                $extra = [
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text'          => "🛒 ទិញ {$pkg->name}",
                                    'callback_data' => 'pkg_buy_' . $pkg->packagesID,
                                ],
                            ],
                        ],
                    ]),
                ];
            }

            $this->telegram->sendMessage($chatId, implode("\n", $lines), $extra);
        }

        // ── Footer ────────────────────────────────────────────────────────────
        $this->telegram->sendMessage(
            $chatId,
            "📩 មានសំណួរ? ទំនាក់ទំនង @" . config('services.telegram.support_username')
        );

        return response()->json(['ok' => true]);
    }

    public function handleBuyCallback(
        string $chatId,
        int    $messageId,
        string $packageId,
        string $requestedBy,
        string $chatType
    ): void {
        // ── Private chat only ─────────────────────────────────────────────────
        if ($chatType !== 'private') {
            $this->telegram->editMessage($chatId, $messageId, '🔒 សូមបើក Bot ក្នុង Private Chat ដើម្បីទិញកញ្ចប់។');
            return;
        }

        $package = Package::where('packagesID', $packageId)->first();

        if (! $package || $package->status !== 'active') {
            $this->telegram->editMessage($chatId, $messageId, '❌ កញ្ចប់នេះមិនមានទេ។');
            return;
        }

        $price    = number_format((float) $package->price, 2);  // ← fixed
        $support  = config('services.telegram.support_username');
        $cycle    = match ($package->billing_cycle) {
            'monthly'  => '១ ខែ',
            'yearly'   => '១ ឆ្នាំ',
            'lifetime' => 'អចិន្ត្រៃយ៍',
            default    => $package->billing_cycle,
        };
        $groups   = $package->isUnlimitedGroups()   ? '∞' : $package->group_limit;
        $payments = $package->isUnlimitedPayments() ? '∞' : $package->payment_limit;

        $text = implode("\n", [
            "🛒 *បញ្ជាក់ការទិញ*",
            "─────────────────────",
            "📦 កញ្ចប់: *{$package->name}*",
            "💰 តម្លៃ: *{$price} USD*",
            "📅 រយៈពេល: {$cycle}",
            "👥 ក្រុម: {$groups}",
            "💳 ការទូទាត់: {$payments}",
            "👤 អ្នកទិញ: {$requestedBy}",
            "─────────────────────",
            "សូមទំនាក់ទំនង @{$support} ដើម្បីបញ្ចប់ការទិញ។",
        ]);

        $keyboard = [
            [
                [
                    'text' => '💬 ទំនាក់ទំនង Admin',
                    'url'  => "https://t.me/{$support}",
                ],
            ],
        ];

        $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }
}