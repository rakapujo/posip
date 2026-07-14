<?php

namespace Tests\Unit\Models;

use App\Models\StockCard;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class StockCardTransactionTypesTest extends TestCase
{
    #[Test]
    public function hpp_reset_is_defined_in_transaction_types()
    {
        $this->assertArrayHasKey('HPP_RESET', StockCard::TRANSACTION_TYPES);
        $this->assertEquals('Reset HPP (Stock Kosong)', StockCard::TRANSACTION_TYPES['HPP_RESET']);
    }
    #[Test]
    public function hpp_reset_is_in_types_no_qty()
    {
        $this->assertContains('HPP_RESET', StockCard::TYPES_NO_QTY);
    }
    #[Test]
    public function hpp_reset_is_not_in_types_in_or_out()
    {
        $this->assertNotContains('HPP_RESET', StockCard::TYPES_IN);
        $this->assertNotContains('HPP_RESET', StockCard::TYPES_OUT);
    }
    #[Test]
    public function all_expected_transaction_types_exist()
    {
        $expectedTypes = [
            'PURCHASE',
            'SALES',
            'PURCHASE_RETURN',
            'SALES_RETURN',
            'ADJUSTMENT_IN',
            'ADJUSTMENT_OUT',
            'STOCK_OPNAME',
            'TRANSFER_IN',
            'TRANSFER_OUT',
            'REPACK_IN',
            'REPACK_OUT',
            'HPP_RESET',
        ];

        foreach ($expectedTypes as $type) {
            $this->assertArrayHasKey($type, StockCard::TRANSACTION_TYPES, "Missing type: {$type}");
        }
    }
    #[Test]
    public function transaction_type_label_accessor_works_for_hpp_reset()
    {
        $stockCard = new StockCard();
        $stockCard->transaction_type = 'HPP_RESET';

        $this->assertEquals('Reset HPP (Stock Kosong)', $stockCard->transaction_type_label);
    }
    #[Test]
    public function types_in_contains_correct_types()
    {
        $expectedIn = ['PURCHASE', 'SALES_RETURN', 'ADJUSTMENT_IN', 'TRANSFER_IN', 'REPACK_IN'];

        foreach ($expectedIn as $type) {
            $this->assertContains($type, StockCard::TYPES_IN, "Missing IN type: {$type}");
        }
    }
    #[Test]
    public function types_out_contains_correct_types()
    {
        $expectedOut = ['SALES', 'PURCHASE_RETURN', 'ADJUSTMENT_OUT', 'TRANSFER_OUT', 'REPACK_OUT'];

        foreach ($expectedOut as $type) {
            $this->assertContains($type, StockCard::TYPES_OUT, "Missing OUT type: {$type}");
        }
    }

    // ==================== EDGE CASE: katalog tipe lengkap & eksak ====================
    #[Test]
    public function katalog_tipe_transaksi_persis_13_entri_tanpa_tambahan_diam_diam()
    {
        // Galak: jumlah tipe HARUS persis 13 (sesuai §2F CLAUDE.md).
        // Kalau ada yang menambah/menghapus tipe, test ini langsung merah.
        $this->assertCount(13, StockCard::TRANSACTION_TYPES);

        // Set kunci HARUS persis ini (tanpa lebih, tanpa kurang).
        $expectedKeys = [
            'PURCHASE', 'SALES', 'PURCHASE_RETURN', 'SALES_RETURN',
            'ADJUSTMENT_IN', 'ADJUSTMENT_OUT', 'STOCK_OPNAME',
            'TRANSFER_IN', 'TRANSFER_OUT', 'REPACK_IN', 'REPACK_OUT',
            'HPP_RESET', 'HPP_CORRECTION',
        ];
        sort($expectedKeys);
        $actualKeys = array_keys(StockCard::TRANSACTION_TYPES);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);
    }
    #[Test]
    public function label_setiap_tipe_persis_sesuai_blueprint()
    {
        // Assertion EKSAK label per tipe — typo di label = bug diam.
        $expectedLabels = [
            'PURCHASE' => 'Pembelian',
            'SALES' => 'Penjualan',
            'PURCHASE_RETURN' => 'Retur Pembelian',
            'SALES_RETURN' => 'Retur Penjualan',
            'ADJUSTMENT_IN' => 'Adjustment Masuk',
            'ADJUSTMENT_OUT' => 'Adjustment Keluar',
            'STOCK_OPNAME' => 'Stock Opname',
            'TRANSFER_IN' => 'Transfer Masuk',
            'TRANSFER_OUT' => 'Transfer Keluar',
            'REPACK_IN' => 'Repack Masuk',
            'REPACK_OUT' => 'Repack Keluar',
            'HPP_RESET' => 'Reset HPP (Stock Kosong)',
            'HPP_CORRECTION' => 'Koreksi HPP',
        ];

        $this->assertSame($expectedLabels, StockCard::TRANSACTION_TYPES);
    }
    #[Test]
    public function hpp_correction_terdefinisi_dan_tergolong_no_qty()
    {
        // HPP_CORRECTION harus ada di katalog + tergolong TYPES_NO_QTY (tidak gerakkan stok).
        $this->assertArrayHasKey('HPP_CORRECTION', StockCard::TRANSACTION_TYPES);
        $this->assertEquals('Koreksi HPP', StockCard::TRANSACTION_TYPES['HPP_CORRECTION']);
        $this->assertContains('HPP_CORRECTION', StockCard::TYPES_NO_QTY);
        $this->assertNotContains('HPP_CORRECTION', StockCard::TYPES_IN);
        $this->assertNotContains('HPP_CORRECTION', StockCard::TYPES_OUT);
    }
    #[Test]
    public function types_in_persis_5_entri_eksak()
    {
        // Galak: set IN harus PERSIS ini (tidak ada tipe nyasar).
        $expected = ['PURCHASE', 'SALES_RETURN', 'ADJUSTMENT_IN', 'TRANSFER_IN', 'REPACK_IN'];
        sort($expected);
        $actual = StockCard::TYPES_IN;
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertCount(5, StockCard::TYPES_IN);
    }
    #[Test]
    public function types_out_persis_5_entri_eksak()
    {
        // Galak: set OUT harus PERSIS ini.
        $expected = ['SALES', 'PURCHASE_RETURN', 'ADJUSTMENT_OUT', 'TRANSFER_OUT', 'REPACK_OUT'];
        sort($expected);
        $actual = StockCard::TYPES_OUT;
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertCount(5, StockCard::TYPES_OUT);
    }
    #[Test]
    public function types_no_qty_persis_2_entri_eksak()
    {
        // Hanya HPP_RESET & HPP_CORRECTION yang tidak menggerakkan stok.
        $expected = ['HPP_RESET', 'HPP_CORRECTION'];
        sort($expected);
        $actual = StockCard::TYPES_NO_QTY;
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertCount(2, StockCard::TYPES_NO_QTY);
    }
    #[Test]
    public function himpunan_in_out_no_qty_saling_lepas_dan_stock_opname_satu_satunya_tak_terklasifikasi()
    {
        // Invariant: IN, OUT, dan NO_QTY saling lepas (tidak ada tipe di dua kategori sekaligus).
        // STOCK_OPNAME sengaja TIDAK masuk IN/OUT/NO_QTY (ditangani khusus oleh modul opname),
        // jadi gabungan ketiga set = seluruh katalog MINUS STOCK_OPNAME.
        $in = StockCard::TYPES_IN;
        $out = StockCard::TYPES_OUT;
        $noQty = StockCard::TYPES_NO_QTY;

        // Saling lepas (disjoint)
        $this->assertEmpty(array_intersect($in, $out), 'IN dan OUT tidak boleh beririsan');
        $this->assertEmpty(array_intersect($in, $noQty), 'IN dan NO_QTY tidak boleh beririsan');
        $this->assertEmpty(array_intersect($out, $noQty), 'OUT dan NO_QTY tidak boleh beririsan');

        // Gabungan ketiganya = seluruh katalog kecuali STOCK_OPNAME
        $union = array_unique(array_merge($in, $out, $noQty));
        sort($union);

        $expected = array_keys(StockCard::TRANSACTION_TYPES);
        $expected = array_values(array_diff($expected, ['STOCK_OPNAME']));
        sort($expected);

        $this->assertSame($expected, $union, 'IN+OUT+NO_QTY harus = seluruh katalog kecuali STOCK_OPNAME');

        // STOCK_OPNAME memang ada di katalog tapi tak terklasifikasi di tiga set itu.
        $this->assertArrayHasKey('STOCK_OPNAME', StockCard::TRANSACTION_TYPES);
        $this->assertNotContains('STOCK_OPNAME', $in);
        $this->assertNotContains('STOCK_OPNAME', $out);
        $this->assertNotContains('STOCK_OPNAME', $noQty);
    }
    #[Test]
    public function label_accessor_fallback_ke_kode_untuk_tipe_tidak_dikenal()
    {
        // Tipe di luar katalog → accessor mengembalikan kode mentah (bukan throw / null).
        $card = new StockCard();
        $card->transaction_type = 'TIPE_NGAWUR';
        $this->assertEquals('TIPE_NGAWUR', $card->transaction_type_label);
    }
    #[Test]
    public function tipe_invalid_tidak_terdaftar_di_katalog_aplikasi()
    {
        // Batas validasi level aplikasi: tipe yang tidak valid TIDAK ada di katalog.
        // Catatan lingkungan: pada SQLite (DB test) kolom enum di-render varchar tanpa
        // CHECK constraint, sehingga penolakan tipe invalid tidak bisa diuji di level DB.
        // Karena itu pertahanan utamanya adalah katalog konstanta ini.
        foreach (['FOO', 'sales', 'Purchase', 'HPP', '', 'TRANSFER'] as $invalid) {
            $this->assertArrayNotHasKey($invalid, StockCard::TRANSACTION_TYPES, "Tipe invalid '{$invalid}' tidak boleh ada di katalog");
        }
    }
}
