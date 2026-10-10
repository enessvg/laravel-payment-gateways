<?php

namespace App\DTOs;

readonly class PaymentCallbackResultDto
{
    public function __construct(
        public PaymentPayResultDto $payment,
        public ?PaymentCallbackResponseDto $response = null,
    ) {}

    public static function payment(PaymentPayResultDto $payment): self
    {
        return new self($payment);
    }

    public static function respond(
        PaymentPayResultDto $payment,
        PaymentCallbackResponseDto $response,
    ): self {
        return new self($payment, $response);
    }

    /**
     * @return array{success: bool, message: string|null, referenceNo: string|null, transactionId: string|null, clientAction: array{type: string, url: string|null, html: string|null, fields: array<string, string>|null}|null}
     */
    public function toArray(): array
    {
        return $this->payment->toArray();
    }
}
