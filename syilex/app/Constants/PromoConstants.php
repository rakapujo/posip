<?php

namespace App\Constants;

/**
 * Konstanta terkait sistem Promo & Diskon.
 *
 * Nilai-nilai di sini align dengan DB schema dan business rules.
 * Ubah di SATU tempat, semua referensi ikut konsisten.
 */
class PromoConstants
{
    /**
     * Jumlah slot diskon yang berasal dari DB promo.
     * Align dengan kolom diskon_1_* s/d diskon_4_* di doc_sales_detail + doc_purchase_detail.
     * Anti-fraud: slot ini SELALU di-rebuild dari DB di CheckoutSalesAction.
     */
    public const DB_DISCOUNT_SLOTS = 4;

    /**
     * Nomor slot untuk diskon manual kasir.
     * diskon_5_* di frontend, divalidasi di buildNotaDiscounts().
     * NOT overridden oleh promo — trusted dari kasir.
     */
    public const MANUAL_DISCOUNT_SLOT = 5;

    /**
     * Total slot diskon tersedia per item (DB + manual).
     */
    public const TOTAL_DISCOUNT_SLOTS = self::DB_DISCOUNT_SLOTS + 1; // 5

    /**
     * Tipe diskon yang valid.
     * Align dengan enum di kolom diskon_*_tipe di DB.
     */
    public const DISCOUNT_TYPES = ['percent', 'nominal', 'none'];

    /**
     * Mode kalkulasi diskon bertingkat.
     * - recursive: slot 2 apply on remaining balance after slot 1 (berantai)
     * - sum: tiap slot apply on original bruto (total diskon dijumlah)
     */
    public const DISCOUNT_MODE_RECURSIVE = 'recursive';
    public const DISCOUNT_MODE_SUM = 'sum';
    public const DISCOUNT_MODES = [
        self::DISCOUNT_MODE_RECURSIVE,
        self::DISCOUNT_MODE_SUM,
    ];

    /**
     * Status promo yang valid (align dengan enum di doc_promo.status).
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
        self::STATUS_INACTIVE,
    ];

    /**
     * Target type untuk promo detail (align dengan doc_promo_detail.target_type).
     */
    public const TARGET_SEMUA = 'semua';
    public const TARGET_PRODUK = 'produk';
    public const TARGET_GRUP = 'grup';
    public const TARGET_KATEGORI = 'kategori';
    public const TARGET_TYPES = [
        self::TARGET_SEMUA,
        self::TARGET_PRODUK,
        self::TARGET_GRUP,
        self::TARGET_KATEGORI,
    ];
}
