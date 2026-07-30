@extends('installer.layout')
@section('content')
<h2 class="text-lg font-semibold text-gray-800 mb-1">Regional & Mata Uang</h2>
<p class="text-gray-500 text-sm mb-6">Sesuaikan format tanggal, waktu, dan mata uang dengan lokasi toko Anda.</p>

<form action="{{ route('installer.step4.post') }}" method="POST" class="space-y-5">
    @csrf
    {{-- Regional --}}
    <div class="border-b pb-4">
        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Regional</h3>
        <div class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Zona Waktu</label>
                <select name="timezone" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    @foreach($timezones as $region => $tzList)
                        <optgroup label="{{ $region }}">
                            @foreach($tzList as $tz)
                                <option value="{{ $tz['value'] }}" {{ $data['timezone'] === $tz['value'] ? 'selected' : '' }}>{{ $tz['label'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Format Tanggal</label>
                    <select name="date_format" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="DD/MM/YYYY" {{ $data['date_format'] === 'DD/MM/YYYY' ? 'selected' : '' }}>DD/MM/YYYY (31/12/2026)</option>
                        <option value="MM/DD/YYYY" {{ $data['date_format'] === 'MM/DD/YYYY' ? 'selected' : '' }}>MM/DD/YYYY (12/31/2026)</option>
                        <option value="YYYY-MM-DD" {{ $data['date_format'] === 'YYYY-MM-DD' ? 'selected' : '' }}>YYYY-MM-DD (2026-12-31)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Format Waktu</label>
                    <select name="time_format" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="HH:mm" {{ $data['time_format'] === 'HH:mm' ? 'selected' : '' }}>24 jam (14:30)</option>
                        <option value="hh:mm A" {{ $data['time_format'] === 'hh:mm A' ? 'selected' : '' }}>12 jam (02:30 PM)</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Currency --}}
    <div>
        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Mata Uang</h3>
        <div class="space-y-3">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Kode</label>
                    <input type="text" name="currency_code" value="{{ old('currency_code', $data['currency_code']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Simbol</label>
                    <input type="text" name="currency_symbol" value="{{ old('currency_symbol', $data['currency_symbol']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Posisi</label>
                    <select name="currency_position" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="before" {{ $data['currency_position'] === 'before' ? 'selected' : '' }}>Sebelum (Rp 10.000)</option>
                        <option value="after" {{ $data['currency_position'] === 'after' ? 'selected' : '' }}>Sesudah (10.000 Rp)</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Separator Ribuan</label>
                    <select name="thousand_separator" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="." {{ $data['thousand_separator'] === '.' ? 'selected' : '' }}>Titik (1.000.000)</option>
                        <option value="," {{ $data['thousand_separator'] === ',' ? 'selected' : '' }}>Koma (1,000,000)</option>
                        <option value=" " {{ $data['thousand_separator'] === ' ' ? 'selected' : '' }}>Spasi (1 000 000)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Separator Desimal</label>
                    <select name="decimal_separator" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="," {{ $data['decimal_separator'] === ',' ? 'selected' : '' }}>Koma (0,50)</option>
                        <option value="." {{ $data['decimal_separator'] === '.' ? 'selected' : '' }}>Titik (0.50)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Desimal Harga</label>
                    <select name="decimal_places" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="0" {{ $data['decimal_places'] == '0' ? 'selected' : '' }}>0 (10.000)</option>
                        <option value="1" {{ $data['decimal_places'] == '1' ? 'selected' : '' }}>1 (10.000,0)</option>
                        <option value="2" {{ $data['decimal_places'] == '2' ? 'selected' : '' }}>2 (10.000,00)</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Desimal Kuantitas</label>
                    <select name="qty_decimal_places" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="0" {{ $data['qty_decimal_places'] == '0' ? 'selected' : '' }}>0 (bilangan bulat)</option>
                        <option value="1" {{ $data['qty_decimal_places'] == '1' ? 'selected' : '' }}>1 desimal</option>
                        <option value="2" {{ $data['qty_decimal_places'] == '2' ? 'selected' : '' }}>2 desimal</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Desimal Persen</label>
                    <select name="percent_decimal_places" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        @foreach([0,1,2,3,4] as $n)
                            <option value="{{ $n }}" {{ (string)($data['percent_decimal_places'] ?? '2') === (string)$n ? 'selected' : '' }}>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="border-t pt-4">
        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Teks</h3>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mode huruf besar</label>
            <select name="uppercase_mode" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="none" {{ ($data['uppercase_mode'] ?? 'code_only') === 'none' ? 'selected' : '' }}>Tidak ada</option>
                <option value="code_only" {{ ($data['uppercase_mode'] ?? 'code_only') === 'code_only' ? 'selected' : '' }}>Hanya kode (nama tetap)</option>
                <option value="all" {{ ($data['uppercase_mode'] ?? 'code_only') === 'all' ? 'selected' : '' }}>Semua field</option>
            </select>
            <p class="text-xs text-gray-400 mt-1">Apakah input teks otomatis di-uppercase.</p>
        </div>
    </div>

    <div class="flex justify-between pt-4">
        <a href="{{ route('installer.step3') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">&larr; Kembali</a>
        <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">Lanjut &rarr;</button>
    </div>
</form>
@endsection
