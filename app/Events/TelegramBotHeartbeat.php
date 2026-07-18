<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class TelegramBotHeartbeat implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $heartbeatAt,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('telegram.bot.status'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'telegram.bot.heartbeat';
    }

    public function broadcastWith(): array
    {
        return [
            'status' => 'online',
            'heartbeat_at' => $this->heartbeatAt,
        ];
    }
}