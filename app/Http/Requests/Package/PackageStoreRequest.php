<?php

namespace App\Http\Requests\Package;

use Illuminate\Foundation\Http\FormRequest;

class PackageStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'billing_cycle' => 'required|in:weekly,monthly,yearly,lifetime',
            'payment_limit' => 'nullable|integer|min:0',
            'group_limit' => 'nullable|integer|min:0',
            'status' => 'nullable|in:active,inactive',
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Package name is required.',
            'price.required' => 'Price is required.',
            'billing_cycle.required' => 'Billing cycle is required.',
        ];
    }
}