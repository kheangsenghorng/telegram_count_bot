<?php

namespace App\Http\Resources\UserSubscription;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Package\PackageResource;

class UserSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'subscription_id' => $this->userSubscriptionsID,
            'subscription_key' => $this->subscription_key,

            'user' => [
                'uuid' => $this->user?->uuid,
                'name' => trim(
                    ($this->user?->first_name ?? '') . ' ' .
                    ($this->user?->last_name ?? '')
                ),
                'email' => $this->user?->email,
            ],

            'package' => new PackageResource(
                $this->whenLoaded('package')
            ),

            'override_payment_limit' => $this->override_payment_limit,
            'override_group_limit' => $this->override_group_limit,

            'payment_used' => $this->payment_used,
            'group_used' => $this->group_used,

            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,

            'status' => $this->status,

            'payment_method' => $this->payment_method,
            'transaction_id' => $this->transaction_id,

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}