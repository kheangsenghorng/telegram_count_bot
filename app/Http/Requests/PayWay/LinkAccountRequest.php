<?php

declare(strict_types=1);

namespace App\Http\Requests\PayWay;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LinkAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
             * PayWay allows only letters and numbers.
             * Length must be between 5 and 24 characters.
             */
            'request_id' => [
                'nullable',
                'string',
                'min:5',
                'max:24',
                'regex:/^[a-zA-Z0-9]+$/',
            ],

            'ctid' => [
                'required',
                'string',
                'min:5',
                'max:24',
                'regex:/^[a-zA-Z0-9]+$/',
            ],

            'token_flag' => [
                'required',
                'string',
                Rule::in([
                    'CITI_FLEX',
                    'CITO_FLEX',
                    'CITO_FIX',
                    'CITR_FLEX',
                ]),
            ],

            'currency' => [
                'required',
                'string',
                Rule::in(['USD', 'KHR']),
            ],

            'return_deeplink' => [
                'nullable',
                'url',
                'max:500',
            ],

            'callback_url' => [
                'nullable',
                'url',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'request_id.regex' => 'The request_id may contain only letters and numbers.',
            'ctid.regex' => 'The ctid may contain only letters and numbers.',
            'token_flag.in' => 'The selected token_flag is invalid.',
            'currency.in' => 'Currency must be USD or KHR.',
        ];
    }
}