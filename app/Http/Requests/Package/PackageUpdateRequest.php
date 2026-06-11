<?php

namespace App\Http\Requests\Package;

use Illuminate\Foundation\Http\FormRequest;

class PackageUpdateRequest extends FormRequest
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
            'name' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'billing_cycle' => 'sometimes|in:weekly,monthly,yearly,lifetime',
            'payment_limit' => 'nullable|integer|min:0',
            'group_limit' => 'nullable|integer|min:0',
            'status' => 'sometimes|in:active,inactive',
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'price.numeric' => 'Price must be a valid number.',
            'billing_cycle.in' => 'Invalid billing cycle selected.',
        ];
    }
}