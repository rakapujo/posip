@extends('installer.layout')
@section('content')
<h2 class="text-lg font-semibold text-gray-800 mb-1">Informasi Toko</h2>
<p class="text-gray-500 text-sm mb-6">Data toko akan ditampilkan di struk, laporan, dan halaman login.</p>

<form action="{{ route('installer.step3.post') }}" method="POST" class="space-y-4">
    @csrf
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nama Toko <span class="text-red-400">*</span></label>
        <input type="text" name="name" value="{{ old('name', $data['name']) }}" placeholder="Contoh: Toko Sejahtera" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Alamat <span class="text-red-400">*</span></label>
        <textarea name="address" rows="2" placeholder="Jl. Contoh No. 123, Kota" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>{{ old('address', $data['address']) }}</textarea>
    </div>
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Telepon <span class="text-red-400">*</span></label>
            <input type="text" name="phone" value="{{ old('phone', $data['phone']) }}" placeholder="021-1234567" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-400">*</span></label>
            <input type="email" name="email" value="{{ old('email', $data['email']) }}" placeholder="info@toko.com" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
        </div>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">NPWP <span class="text-gray-400 text-xs">(opsional)</span></label>
        <input type="text" name="npwp" value="{{ old('npwp', $data['npwp']) }}" placeholder="00.000.000.0-000.000" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">URL Toko <span class="text-gray-400 text-xs">(opsional)</span></label>
        <input type="text" name="url" value="{{ old('url', $data['url']) }}" placeholder="https://pos.tokomu.com" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        <p class="text-xs text-gray-400 mt-1">Terdeteksi dari alamat install; ubah jika domain produksi berbeda.</p>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Footer Struk</label>
        <textarea name="receipt_footer" rows="2" placeholder="Terima Kasih!" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">{{ old('receipt_footer', $data['receipt_footer']) }}</textarea>
        <p class="text-xs text-gray-400 mt-1">Ditampilkan di bawah struk (PDF, thermal, online).</p>
    </div>

    <div class="flex justify-between pt-4">
        <a href="{{ route('installer.step2') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">&larr; Kembali</a>
        <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">Lanjut &rarr;</button>
    </div>
</form>
@endsection
