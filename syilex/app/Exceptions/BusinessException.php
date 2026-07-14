<?php

namespace App\Exceptions;

use Exception;

/**
 * Base class untuk semua business logic exception.
 *
 * Gunakan ini untuk error yang berasal dari violasi aturan bisnis
 * (stok kurang, promo konflik, dll) — BUKAN untuk bug teknis.
 *
 * Subclass ini akan di-render sebagai HTTP 422 di `bootstrap/app.php`
 * dengan format response standar `BaseApiController::error()`.
 */
class BusinessException extends Exception
{
    /**
     * HTTP status code untuk response.
     */
    protected int $statusCode = 422;

    /**
     * Optional data tambahan untuk response body (misal item_id yang salah).
     */
    protected array $context = [];

    public function __construct(string $message = '', int $statusCode = 422, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
        $this->context = $context;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
