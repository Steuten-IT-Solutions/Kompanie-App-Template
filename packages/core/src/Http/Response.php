<?php

declare(strict_types=1);

namespace KompanieApp\Core\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly mixed $data,
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function created(mixed $data): self
    {
        return new self($data, 201);
    }

    public static function error(ApiError $e): self
    {
        return new self(['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra, $e->status);
    }
}
