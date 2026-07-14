<?php

namespace App\Exceptions;

/**
 * Thrown saat stok tidak mencukupi untuk operasi (checkout, transfer, adjustment).
 *
 * Context biasanya berisi: product_id, required_qty, available_qty.
 */
class StockInsufficientException extends BusinessException
{
    public static function forProduct(int $productId, string $productName, float $required, float $available): self
    {
        return new self(
            "Stok produk '{$productName}' tidak mencukupi. Butuh: {$required}, tersedia: {$available}.",
            422,
            [
                'product_id' => $productId,
                'product_name' => $productName,
                'required_qty' => $required,
                'available_qty' => $available,
            ]
        );
    }
}
