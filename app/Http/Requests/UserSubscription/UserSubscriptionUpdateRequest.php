<?php

namespace App\Http\Requests\UserSubscription;

use Illuminate\Foundation\Http\FormRequest;

class UserSubscriptionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'package_id' => 'sometimes|exists:packages,packagesID',

            'override_payment_limit' => 'nullable|integer|min:0',
            'override_group_limit' => 'nullable|integer|min:0',

            'payment_used' => 'nullable|integer|min:0',
            'group_used' => 'nullable|integer|min:0',

            'starts_at' => 'sometimes|date',
            'ends_at' => 'nullable|date',

            'status' => 'sometimes|in:active,expired,cancelled,suspended',

            'payment_method' => 'nullable|string|max:255',
            'transaction_id' => 'nullable|string|max:255',
        ];
    }
}