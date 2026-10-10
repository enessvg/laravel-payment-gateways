<?php

namespace App\DTOs;

readonly class PaymentCallbackDto
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }

    public function string(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
