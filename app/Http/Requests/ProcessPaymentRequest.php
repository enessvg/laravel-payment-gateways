<?php

namespace App\Http\Requests;

use App\Interfaces\PaymentInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProcessPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(PaymentInterface $paymentGateway): array
    {
        $rules = [
            '3d_secure' => ['required', 'boolean'],
        ];

        if (! $paymentGateway->requiresServerSideCard()) {
            return [
                ...$rules,
                'card' => ['prohibited'],
            ];
        }

        return [
            ...$rules,
            'card' => ['required', 'array:pan,month,year,cvv,cardHolder'],
            'card.pan' => ['required', 'string', 'regex:/^\d{13,19}$/'],
            'card.month' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])$/'],
            'card.year' => ['required', 'string', 'regex:/^\d{4}$/'],
            'card.cvv' => ['required', 'string', 'regex:/^\d{3,4}$/'],
            'card.cardHolder' => ['required', 'string', 'max:100'],
        ];
    }
}
