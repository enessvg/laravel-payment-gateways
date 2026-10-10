<?php

namespace App\DTOs;

use Symfony\Component\HttpFoundation\Response;

readonly class PaymentCallbackResponseDto
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $body,
        public int $statusCode,
        public array $headers,
    ) {}

    public static function plainText(
        string $body,
        int $statusCode = Response::HTTP_OK,
    ): self {
        return new self(
            body: $body,
            statusCode: $statusCode,
            headers: ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
