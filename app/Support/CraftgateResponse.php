<?php

namespace App\Support;

use JsonException;
use UnexpectedValueException;

final class CraftgateResponse
{
    /**
     * @return array{success: bool, data: array<string, mixed>|null, error: array{code: string|null, group: string|null, message: string|null, provider: array<string, mixed>|null}|null}
     *
     * @throws JsonException
     * @throws UnexpectedValueException
     */
    public static function parse(string $response): array
    {
        $decoded = json_decode(
            $response,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Unexpected Craftgate response.');
        }

        $data = $decoded['data'] ?? null;
        $error = $decoded['errors'] ?? null;

        if ($data !== null && ! is_array($data)) {
            throw new UnexpectedValueException('Unexpected Craftgate response data.');
        }

        if ($error !== null && ! is_array($error)) {
            throw new UnexpectedValueException('Unexpected Craftgate response error.');
        }

        return [
            'success' => empty($error),
            'data' => $data,
            'error' => empty($error) ? null : [
                'code' => self::nullableString($error['errorCode'] ?? null),
                'group' => self::nullableString($error['errorGroup'] ?? null),
                'message' => self::nullableString($error['errorDescription'] ?? null),
                'provider' => is_array($error['providerError'] ?? null)
                    ? $error['providerError']
                    : null,
            ],
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $string = (string) $value;

        return $string !== '' ? $string : null;
    }
}
