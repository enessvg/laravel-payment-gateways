<?php

namespace App\DTOs;

readonly class PaymentClientActionDto
{
    /**
     * @param  array<string, string>|null  $fields
     */
    private function __construct(
        public string $type,
        public ?string $url,
        public ?string $html,
        public ?array $fields,
    ) {}

    public static function redirect(string $url): self
    {
        return new self(
            type: 'redirect',
            url: $url,
            html: null,
            fields: null,
        );
    }

    public static function html(string $html): self
    {
        return new self(
            type: 'html',
            url: null,
            html: $html,
            fields: null,
        );
    }

    /**
     * @param  array<string, string>  $fields
     */
    public static function formPost(string $url, array $fields): self
    {
        return new self(
            type: 'form_post',
            url: $url,
            html: null,
            fields: $fields,
        );
    }

    /**
     * @return array{type: string, url: string|null, html: string|null, fields: array<string, string>|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'url' => $this->url,
            'html' => $this->html,
            'fields' => $this->fields,
        ];
    }
}
