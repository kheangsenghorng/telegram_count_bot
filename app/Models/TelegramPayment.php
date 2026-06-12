<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TelegramPayment extends Model
{
    protected $table = 'telegram_payments';

    protected $primaryKey = 'telegram_paymentID';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'telegram_paymentID',
        'user_id',
        'telegram_group_id',
        'subscription_id',
        'currency',
        'amount',
        'payer_name',
        'payer_account',
        'merchant_name',
        'payment_method',
        'bank_code',
        'trx_id',
        'apv',
        'payment_date',
        'report_date',
        'report_month',
        'report_year',
        'raw_message',
        'parsed_successfully',
        'is_duplicate',
        'status',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            if (!$payment->telegram_paymentID) {
                $payment->telegram_paymentID = (string) Str::uuid();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function subscription()
    {
        return $this->belongsTo(
            UserSubscription::class,
            'subscription_id',
            'userSubscriptionsID'
        );
    }

    public function telegramGroup()
    {
        return $this->belongsTo(
            TelegramGroup::class,
            'telegram_group_id',
            'telegramGroupsID'
        );
    }
}