<?php

namespace App\DTOs;

readonly class PaymentPayResultDto
{
    public function __construct(
        public bool $success,
        public ?string $message,
        public ?string $referenceNo,
        public ?string $transactionId,
        public ?PaymentClientActionDto $clientAction = null,
    ) {}

    public static function succeeded(?string $referenceNo, ?string $transactionId = null): self
    {
        return new self(
            success: true,
            message: null,
            referenceNo: $referenceNo,
            transactionId: $transactionId,
        );
    }

    public static function requiresClientAction(
        string $referenceNo,
        PaymentClientActionDto $action,
    ): self {
        return new self(
            success: true,
            message: null,
            referenceNo: $referenceNo,
            transactionId: null,
            clientAction: $action,
        );
    }

    public static function failed(string $message, ?string $referenceNo = null): self
    {
        return new self(
            success: false,
            message: $message,
            referenceNo: $referenceNo,
            transactionId: null,
        );
    }

    /**
     * @return array{success: bool, message: string|null, referenceNo: string|null, transactionId: string|null, clientAction: array{type: string, url: string|null, html: string|null, fields: array<string, string>|null}|null}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'referenceNo' => $this->referenceNo,
            'transactionId' => $this->transactionId,
            'clientAction' => $this->clientAction?->toArray(),
        ];
    }
}
