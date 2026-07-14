@extends('installer.layout')
@section('content')
<h2 class="text-lg font-semibold text-gray-800 mb-1">Akun Admin</h2>
<p class="text-gray-500 text-sm mb-6">Buat akun administrator utama. Ini adalah akun pertama dengan akses penuh ke seluruh sistem.</p>

<form action="{{ route('installer.step7.post') }}" method="POST" class="space-y-4">
    @csrf
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nama Lengkap <span class="text-red-400">*</span></label>
        <input type="text" name="name" value="{{ old('name', $data['name']) }}" placeholder="Administrator" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-400">*</span></label>
        <input type="email" name="email" value="{{ old('email', $data['email']) }}" placeholder="admin@toko.com" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-red-400">*</span></label>
        <input type="password" name="password" placeholder="Minimal 8 karakter" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required minlength="8">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Konfirmasi Password <span class="text-red-400">*</span></label>
        <input type="password" name="password_confirmation" placeholder="Ketik ulang password" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required minlength="8">
    </div>

    <div class="flex justify-between pt-4">
        <a href="{{ route('installer.step6') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">&larr; Kembali</a>
        <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">Lanjut &rarr;</button>
    </div>
</form>
@endsection
