@extends('installer.layout')
@section('content')
<h2 class="text-lg font-semibold text-gray-800 mb-1">Pajak & Perhitungan</h2>
<p class="text-gray-500 text-sm mb-6">Konfigurasi pajak, pembulatan, dan mode perhitungan untuk transaksi.</p>

<form action="{{ route('installer.step5.post') }}" method="POST" class="space-y-5">
    @csrf

    {{-- Tax --}}
    <div class="border-b pb-4">
        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Pajak</h3>
        <div class="grid grid-cols-2 gap-6">
            <div class="space-y-3">
                <p class="text-xs font-semibold text-gray-500">Pajak Pembelian</p>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Nama</label>
                        <input type="text" name="tax_purchase_name" value="{{ old('tax_purchase_name', $data['tax_purchase_name']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Persentase (%)</label>
                        <input type="number" step="0.01" name="tax_purchase_percent" value="{{ old('tax_purchase_percent', $data['tax_purchase_percent']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="tax_purchase_included_in_hpp" {{ old('tax_purchase_included_in_hpp', $data['tax_purchase_included_in_hpp']) ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600">
                    Pajak termasuk dalam HPP
                </label>
                <p class="text-xs text-gray-400">Jika dicentang, pajak pembelian akan dihitung sebagai bagian dari harga pokok produk.</p>
            </div>
            <div class="space-y-3">
                <p class="text-xs font-semibold text-gray-500">Pajak Penjualan</p>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Nama</label>
                        <input type="text" name="tax_sales_name" value="{{ old('tax_sales_name', $data['tax_sales_name']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Persentase (%)</label>
                        <input type="number" step="0.01" name="tax_sales_percent" value="{{ old('tax_sales_percent', $data['tax_sales_percent']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Rounding --}}
    <div class="border-b pb-4">
        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Pembulatan Penjualan</h3>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Metode</label>
                <select name="rounding_sales_method" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="none" {{ $data['rounding_sales_method'] === 'none' ? 'selected' : '' }}>Tidak ada pembulatan</option>
                    <option value="round" {{ $data['rounding_sales_method'] === 'round' ? 'selected' : '' }}>Bulatkan (terdekat)</option>
                    <option value="floor" {{ $data['rounding_sales_method'] === 'floor' ? 'selected' : '' }}>Bulatkan ke bawah</option>
                    <option value="ceil" {{ $data['rounding_sales_method'] === 'ceil' ? 'selected' : '' }}>Bulatkan ke atas</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Presisi</label>
                <select name="rounding_sales_precision" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="1" {{ $data['rounding_sales_precision'] == '1' ? 'selected' : '' }}>Rp 1</option>
                    <option value="10" {{ $data['rounding_sales_precision'] == '10' ? 'selected' : '' }}>Rp 10</option>
                    <option value="100" {{ $data['rounding_sales_precision'] == '100' ? 'selected' : '' }}>Rp 100 (umum)</option>
                    <option value="500" {{ $data['rounding_sales_precision'] == '500' ? 'selected' : '' }}>Rp 500</option>
                    <option value="1000" {{ $data['rounding_sales_precision'] == '1000' ? 'selected' : '' }}>Rp 1.000</option>
                </select>
            </div>
        </div>
        <p class="text-xs text-gray-400 mt-2">Contoh: Rp 12.345 dengan pembulatan terdekat Rp 100 → Rp 12.300</p>
    </div>

    {{-- Stock --}}
    <div class="border-b pb-4">
        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Kontrol Stok</h3>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mode Stok Negatif</label>
            <select name="negative_mode" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="block" {{ $data['negative_mode'] === 'block' ? 'selected' : '' }}>Blokir — tidak bisa jual jika stok habis</option>
                <option value="warn" {{ $data['negative_mode'] === 'warn' ? 'selected' : '' }}>Peringatan — bisa jual, tapi muncul peringatan</option>
            </select>
        </div>
    </div>

    {{-- Discount Mode --}}
    <div class="border-b pb-4">
        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Mode Diskon Bertingkat</h3>
        <div>
            <select name="discount_mode" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="recursive" {{ $data['discount_mode'] === 'recursive' ? 'selected' : '' }}>Bertingkat (Recursive) — direkomendasikan</option>
                <option value="sum" {{ $data['discount_mode'] === 'sum' ? 'selected' : '' }}>Dijumlahkan (Sum)</option>
            </select>
        </div>
        <div class="mt-2 bg-gray-50 rounded-lg p-3 text-xs text-gray-600">
            <p class="font-semibold mb-1">Contoh: Harga Rp 100.000, Diskon 1: 10%, Diskon 2: 5%</p>
            <p><strong>Bertingkat:</strong> Disc 1 = 10% × 100.000 = 10.000, Disc 2 = 5% × <em>90.000</em> = 4.500 → Bayar <strong>Rp 85.500</strong></p>
            <p><strong>Dijumlahkan:</strong> Disc 1 = 10% × 100.000 = 10.000, Disc 2 = 5% × <em>100.000</em> = 5.000 → Bayar <strong>Rp 85.000</strong></p>
        </div>
    </div>

    {{-- Cost Allocation --}}
    <div class="border-b pb-4">
        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Alokasi Biaya ke HPP</h3>
        <div>
            <select name="cost_allocation_mode" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="by_value" {{ $data['cost_allocation_mode'] === 'by_value' ? 'selected' : '' }}>Proporsional (By Value) — direkomendasikan</option>
                <option value="equal" {{ $data['cost_allocation_mode'] === 'equal' ? 'selected' : '' }}>Merata (Equal)</option>
            </select>
        </div>
        <div class="mt-2 bg-gray-50 rounded-lg p-3 text-xs text-gray-600">
            <p class="font-semibold mb-1">Contoh: PO 2 item + ongkir Rp 10.000</p>
            <p>Item A: Rp 80.000, Item B: Rp 20.000</p>
            <p><strong>Proporsional:</strong> A = 80% × 10.000 = 8.000, B = 20% × 10.000 = 2.000</p>
            <p><strong>Merata:</strong> A = 5.000, B = 5.000</p>
        </div>
    </div>

    {{-- Modul Elektronik --}}
    <div class="border-b pb-4">
        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Modul Elektronik (Serial)</h3>
        <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 cursor-pointer">
            <input type="checkbox" name="elektronik_enabled" {{ old('elektronik_enabled', $data['elektronik_enabled'] ?? true) ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 w-5 h-5">
            <div>
                <p class="text-sm font-medium text-gray-700">Aktifkan Modul Elektronik / Serial Number</p>
                <p class="text-xs text-gray-400">Untuk toko elektronik: tracking IMEI/serial per unit, pembelian serial, koreksi HPP serial, dll. Nonaktifkan jika tidak diperlukan.</p>
            </div>
        </label>
    </div>

    {{-- Price Input Mode --}}
    <div>
        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Mode Input Harga Produk</h3>
        <div>
            <select name="price_input_mode" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="auto" {{ $data['price_input_mode'] === 'auto' ? 'selected' : '' }}>Otomatis (Auto) — direkomendasikan</option>
                <option value="manual" {{ $data['price_input_mode'] === 'manual' ? 'selected' : '' }}>Manual</option>
            </select>
        </div>
        <div class="mt-2 bg-gray-50 rounded-lg p-3 text-xs text-gray-600">
            <p class="font-semibold mb-1">Contoh: Indomie — KARTON (40 pcs), DUS (5 pcs), PCS</p>
            <p><strong>Otomatis:</strong> Isi harga KARTON = Rp 120.000 → DUS = Rp 15.000, PCS = Rp 3.000 (dihitung otomatis)</p>
            <p><strong>Manual:</strong> Isi semua harga sendiri (bisa beda margin per satuan)</p>
        </div>
        <div class="mt-2 bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-xs text-yellow-700">
            ⚠️ Setting ini <strong>tidak bisa diubah</strong> setelah ada produk di database. Pilih dengan hati-hati.
        </div>
    </div>

    <div class="flex justify-between pt-4">
        <a href="{{ route('installer.step4') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">&larr; Kembali</a>
        <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">Lanjut &rarr;</button>
    </div>
</form>
@endsection
