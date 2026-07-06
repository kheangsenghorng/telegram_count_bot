<?php

namespace App\Jobs;

use App\Models\UserSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendRenewalReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public string $subscriptionId,
    ) {}

    public function handle(): void
    {
        $sub = UserSubscription::query()
            ->with(['user', 'package'])
            ->find($this->subscriptionId);

        if (! $sub || ! $sub->user) {
            return;
        }

        // Double-check: only once, ever
        if ($sub->renewal_reminded_at !== null) {
            return;
        }

        $chatId = $sub->user->telegram_id; // ← ADJUST: your Telegram chat ID column

        if (! $chatId) {
            return;
        }

        $packageName = $sub->package->name ?? 'Package'; // ← ADJUST if needed
        $endsAt      = $sub->ends_at?->timezone('Asia/Phnom_Penh')->format('d/m/Y H:i');
        $payUrl      = config('app.pay_url', 'https://botcount.servicefixit.me/pay'); // ← ADJUST

        $text = "⏰ *ការជូនដំណឹង*\n\n"
            . "គម្រោង *{$packageName}* របស់អ្នកនឹងផុតកំណត់នៅ *{$endsAt}*។\n\n"
            . "សូមបង់ប្រាក់បន្តគម្រោង ដើម្បីកុំឱ្យសេវាកម្មរអាក់រអួល៖\n{$payUrl}";

        $token = config('services.telegram.bot_token'); // ← ADJUST config key

        $response = Http::asForm()->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            [
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]
        );

        if ($response->successful()) {
            $sub->forceFill(['renewal_reminded_at' => now()])->save();
        } else {
            Log::warning('Renewal reminder failed', [
                'subscription' => $this->subscriptionId,
                'body'         => $response->body(),
            ]);
        }
    }
}