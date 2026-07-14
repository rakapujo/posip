<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sumber data terpadu untuk laporan Pembelian — gabungan:
 *   - Purchase Order biasa  (doc_purchase_order / doc_purchase_order_detail)
 *   - Pembelian Serial      (doc_serial_intake  / serial_units)
 *
 * Pembelian serial = pembelian NYATA (punya stok + hutang) → WAJIB ikut di laporan
 * pembelian. Kedua sumber dinormalkan ke kolom yang sama lalu di-UNION ALL.
 *
 * Branch PO dibuat identik dengan query lama → tanpa data serial, hasil = sebelumnya
 * (laporan PO existing tidak berubah).
 *
 * @param string $source 'all' (default) | 'po' | 'serial'
 */
class PurchaseReportSource
{
    /**
     * UNION level DOKUMEN — 1 baris per dokumen pembelian (PO / intake serial).
     * Kolom: ulid, sumber, tanggal_po, nomor_dokumen, supplier_id, warehouse_id,
     * tempo_hari, tanggal_jatuh_tempo, subtotal, total_diskon_header,
     * total_setelah_diskon, total_biaya_tambahan, grand_total, diskon_1..3_*, details_count.
     */
    public static function documents(string $dateFrom, string $dateToEnd, string $source = 'all'): Builder
    {
        $po = DB::table('doc_purchase_order as dpo')
            ->where('dpo.status', 'approved')
            ->where('dpo.tanggal_po', '>=', $dateFrom)
            ->where('dpo.tanggal_po', '<=', $dateToEnd)
            ->select([
                'dpo.ulid',
                DB::raw("'po' as sumber"),
                'dpo.tanggal_po as tanggal_po',
                'dpo.nomor_dokumen',
                'dpo.supplier_id',
                'dpo.warehouse_id',
                'dpo.tempo_hari',
                'dpo.tanggal_jatuh_tempo',
                'dpo.subtotal',
                'dpo.total_diskon_header',
                'dpo.total_setelah_diskon',
                'dpo.total_biaya_tambahan',
                'dpo.grand_total',
                'dpo.diskon_1_tipe', 'dpo.diskon_1_nilai', 'dpo.diskon_1_hasil',
                'dpo.diskon_2_tipe', 'dpo.diskon_2_nilai', 'dpo.diskon_2_hasil',
                'dpo.diskon_3_tipe', 'dpo.diskon_3_nilai', 'dpo.diskon_3_hasil',
                DB::raw('(SELECT COUNT(*) FROM doc_purchase_order_detail WHERE po_id = dpo.id) as details_count'),
            ]);

        $serial = DB::table('doc_serial_intake as dsi')
            ->where('dsi.status', 'approved')
            ->where('dsi.tanggal', '>=', $dateFrom)
            ->where('dsi.tanggal', '<=', $dateToEnd)
            ->select([
                'dsi.ulid',
                DB::raw("'serial' as sumber"),
                'dsi.tanggal as tanggal_po',
                'dsi.nomor_dokumen',
                'dsi.supplier_id',
                'dsi.warehouse_id',
                'dsi.tempo_hari',
                'dsi.tanggal_jatuh_tempo',
                'dsi.subtotal',
                'dsi.total_diskon_header',
                'dsi.total_setelah_diskon',
                'dsi.total_biaya_tambahan',
                'dsi.grand_total',
                'dsi.diskon_1_tipe', 'dsi.diskon_1_nilai', 'dsi.diskon_1_hasil',
                'dsi.diskon_2_tipe', 'dsi.diskon_2_nilai', 'dsi.diskon_2_hasil',
                'dsi.diskon_3_tipe', 'dsi.diskon_3_nilai', 'dsi.diskon_3_hasil',
                'dsi.total_unit as details_count',
            ]);

        return self::combine($po, $serial, $source);
    }

    /**
     * UNION level BARIS PRODUK — 1 baris per detail PO / per unit serial.
     * Kolom: product_id, sumber, supplier_id, warehouse_id, tanggal_po, nomor_dokumen,
     * unit_used, qty_in_unit, qty_in_base, harga_per_unit, harga_bruto,
     * total_diskon_item, subtotal, cost_per_unit, line_seq.
     *
     * Serial: 1 unit = qty 1, subtotal = harga_modal (gross), diskon item = 0,
     * cost_per_unit = landed (modal + alokasi diskon/biaya header).
     */
    public static function lines(string $dateFrom, string $dateToEnd, string $source = 'all'): Builder
    {
        $po = DB::table('doc_purchase_order_detail as dpod')
            ->join('doc_purchase_order as dpo', 'dpo.id', '=', 'dpod.po_id')
            ->where('dpo.status', 'approved')
            ->where('dpo.tanggal_po', '>=', $dateFrom)
            ->where('dpo.tanggal_po', '<=', $dateToEnd)
            ->select([
                'dpod.product_id',
                DB::raw("'po' as sumber"),
                'dpo.supplier_id',
                'dpo.warehouse_id',
                'dpo.tanggal_po as tanggal_po',
                'dpo.nomor_dokumen',
                'dpod.unit_used',
                'dpod.qty_in_unit',
                'dpod.qty_in_base',
                'dpod.harga_per_unit',
                'dpod.harga_bruto',
                'dpod.total_diskon_item',
                'dpod.subtotal',
                'dpod.cost_per_unit',
                DB::raw('dpod.id as line_seq'),
            ]);

        $serial = DB::table('serial_units as su')
            ->join('doc_serial_intake as dsi', 'dsi.id', '=', 'su.intake_id')
            ->where('dsi.status', 'approved')
            ->where('dsi.tanggal', '>=', $dateFrom)
            ->where('dsi.tanggal', '<=', $dateToEnd)
            ->whereNull('su.deleted_at')
            ->select([
                'su.product_id',
                DB::raw("'serial' as sumber"),
                'dsi.supplier_id',
                'dsi.warehouse_id',
                'dsi.tanggal as tanggal_po',
                'dsi.nomor_dokumen',
                DB::raw("'UNIT' as unit_used"),
                DB::raw('1 as qty_in_unit'),
                DB::raw('1 as qty_in_base'),
                'su.harga_modal as harga_per_unit',
                'su.harga_modal as harga_bruto',
                DB::raw('0 as total_diskon_item'),
                'su.harga_modal as subtotal',
                'su.cost_per_unit',
                DB::raw('su.id as line_seq'),
            ]);

        return self::combine($po, $serial, $source);
    }

    private static function combine(Builder $po, Builder $serial, string $source): Builder
    {
        return match ($source) {
            'po' => $po,
            'serial' => $serial,
            default => $po->unionAll($serial),
        };
    }
}
