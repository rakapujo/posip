<?php

namespace App\Exceptions;

/**
 * Thrown saat dokumen dalam state yang tidak allow operasi.
 * Contoh:
 * - Edit promo yang sudah approved
 * - Approve dokumen yang sudah approved
 * - Cancel dokumen yang sudah completed
 */
class DocumentStateException extends BusinessException
{
    public static function cannotEdit(string $entity, string $currentStatus): self
    {
        return new self(
            "{$entity} dengan status '{$currentStatus}' tidak dapat diedit. Batalkan approval terlebih dahulu.",
            422,
            ['current_status' => $currentStatus]
        );
    }

    public static function cannotTransition(string $entity, string $fromStatus, string $toStatus): self
    {
        return new self(
            "Transisi {$entity} dari '{$fromStatus}' ke '{$toStatus}' tidak diizinkan.",
            422,
            ['from' => $fromStatus, 'to' => $toStatus]
        );
    }
}
