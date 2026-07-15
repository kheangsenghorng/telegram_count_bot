<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PayWayPayment extends Model
{
    protected $fillable = [
        'merchant_ref_no',
        'payment_link_id',
        'create_log_id',
        'tran_id',
        'title',
        'amount',
        'currency',
        'description',
        'payment_limit',
        'expired_date',
        'payment_link',
        'status',
        'gateway_status',
        'paid_at',
        'create_response',
        'callback_payload',
        'verification_response',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_limit' => 'integer',
            'expired_date' => 'integer',
            'paid_at' => 'datetime',
            'create_response' => 'array',
            'callback_payload' => 'array',
            'verification_response' => 'array',
        ];
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->expired_date !== null
            && $this->expired_date <= now()->timestamp;
    }
}