@extends('installer.layout')
@section('content')
<h2 class="text-lg font-semibold text-gray-800 mb-1">Konfigurasi Database</h2>
<p class="text-gray-500 text-sm mb-6">Masukkan kredensial database MySQL Anda. Database harus sudah dibuat sebelumnya.</p>

<form action="{{ route('installer.step2.post') }}" method="POST" class="space-y-4">
    @csrf
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Host</label>
            <input type="text" name="host" value="{{ old('host', $data['host']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Port</label>
            <input type="text" name="port" value="{{ old('port', $data['port']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
        </div>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nama Database</label>
        <input type="text" name="database" value="{{ old('database', $data['database']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
        <p class="text-xs text-gray-400 mt-1">Database harus sudah dibuat di MySQL. Wizard tidak membuat database otomatis.</p>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
        <input type="text" name="username" value="{{ old('username', $data['username']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
        <input type="password" name="password" value="{{ old('password', $data['password']) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
    </div>

    <div class="flex justify-between pt-4">
        <a href="{{ route('installer.step1') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">&larr; Kembali</a>
        <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">
            Test Koneksi & Lanjut &rarr;
        </button>
    </div>
</form>
@endsection
