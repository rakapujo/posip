@extends('installer.layout')
@section('content')
<div class="text-center py-6">
    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg class="w-8 h-8 text-green-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
    </div>
    <h2 class="text-xl font-bold text-gray-800 mb-2">Instalasi Berhasil!</h2>
    <p class="text-gray-500 text-sm mb-6">POSIP siap digunakan. Silakan login dengan akun admin Anda.</p>

    <div class="bg-gray-50 rounded-lg p-4 inline-block text-left text-sm mb-6">
        <p class="text-gray-600"><strong>Email:</strong> {{ $adminEmail }}</p>
        <p class="text-gray-600"><strong>Password:</strong> (yang Anda isi di Step 7)</p>
    </div>

    @if($seedMode === 'demo')
    <div class="mb-6 bg-orange-50 border border-orange-200 rounded-lg p-4 text-left text-xs text-orange-800 max-w-lg mx-auto">
        <p class="font-semibold mb-2">⚠️ Akun Demo Masih Aktif</p>
        <p>Instalasi dengan data demo juga membuat akun contoh berikut (password: <code class="bg-orange-100 px-1 rounded">password</code>):</p>
        <ul class="mt-2 space-y-1 list-disc list-inside">
            <li><code>admin@posip.com</code> — super-admin</li>
            <li><code>manager@posip.com</code> — admin</li>
            <li><code>kasir@posip.com</code> — kasir</li>
            <li><code>gudang@posip.com</code> — gudang</li>
        </ul>
        <p class="mt-2">Untuk production, hapus atau ubah password akun-akun ini setelah login.</p>
    </div>
    @endif

    <div>
        <a href="/" class="inline-block px-8 py-3 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition">
            Masuk ke Aplikasi &rarr;
        </a>
    </div>

    <div class="mt-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-left text-xs text-yellow-700">
        <p class="font-semibold mb-1">Catatan Keamanan:</p>
        <p>Halaman instalasi ini sudah dikunci otomatis. Tidak ada yang bisa mengaksesnya lagi.</p>
        <p class="mt-1">Jika perlu reinstall, hapus file <code class="bg-yellow-100 px-1 rounded">storage/installed</code> di server.</p>
    </div>
</div>
@endsection
