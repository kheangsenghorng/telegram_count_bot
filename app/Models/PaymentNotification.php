<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentNotification extends Model
{
    use HasUuids;

    protected $table = 'payment_notifications';

    protected $primaryKey = 'paymentNotificationsID';

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'telegram_group_id',
        'telegram_payment_id',
        'telegram_message_id',
        'raw_message',
        'processed',
        'processed_at',
    ];

    protected $casts = [
        'processed' => 'boolean',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['paymentNotificationsID'];
    }

    public function telegramGroup(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }

    public function telegramPayment(): BelongsTo
    {
        return $this->belongsTo(TelegramPayment::class, 'telegram_payment_id');
    }
}