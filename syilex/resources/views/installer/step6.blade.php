@extends('installer.layout')
@section('content')
<h2 class="text-lg font-semibold text-gray-800 mb-1">Promo & Diskon</h2>
<p class="text-gray-500 text-sm mb-6">Kontrol kebijakan diskon manual yang bisa diberikan kasir saat transaksi POS.</p>

<form action="{{ route('installer.step6.post') }}" method="POST" class="space-y-5">
    @csrf

    <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 cursor-pointer">
        <input type="checkbox" name="enabled" {{ old('enabled', $data['enabled']) ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 w-5 h-5">
        <div>
            <p class="text-sm font-medium text-gray-700">Aktifkan Fitur Promo</p>
            <p class="text-xs text-gray-400">Jika dimatikan, semua fitur diskon & promosi dinonaktifkan.</p>
        </div>
    </label>

    <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 cursor-pointer">
        <input type="checkbox" name="allow_manual_discount" {{ old('allow_manual_discount', $data['allow_manual_discount']) ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 w-5 h-5">
        <div>
            <p class="text-sm font-medium text-gray-700">Izinkan Diskon Manual oleh Kasir</p>
            <p class="text-xs text-gray-400">Kasir bisa memberikan diskon per item atau per nota saat checkout.</p>
        </div>
    </label>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Maks Diskon (%)</label>
            <input type="number" step="0.01" name="max_manual_discount_percent" value="{{ old('max_manual_discount_percent', $data['max_manual_discount_percent']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
            <p class="text-xs text-gray-400 mt-1">Batas maksimal diskon persen yang bisa diberikan kasir. 100 = tidak ada batas.</p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Maks Diskon Nominal <span class="text-gray-400 text-xs">(opsional)</span></label>
            <input type="number" name="max_manual_discount_nominal" value="{{ old('max_manual_discount_nominal', $data['max_manual_discount_nominal']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <p class="text-xs text-gray-400 mt-1">Batas maksimal diskon nominal (Rp). Kosong = tidak ada batas.</p>
        </div>
    </div>

    <div class="flex justify-between pt-4">
        <a href="{{ route('installer.step5') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">&larr; Kembali</a>
        <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">Lanjut &rarr;</button>
    </div>
</form>
@endsection
