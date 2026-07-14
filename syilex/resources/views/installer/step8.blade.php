@extends('installer.layout')
@section('content')
<h2 class="text-lg font-semibold text-gray-800 mb-1">Data Awal</h2>
<p class="text-gray-500 text-sm mb-6">Pilih data yang ingin dimasukkan saat instalasi.</p>

<form action="{{ route('installer.step8.post') }}" method="POST" class="space-y-5" id="step8Form">
    @csrf

    {{-- Seed Mode --}}
    <div class="space-y-3">
        <label class="flex items-start gap-3 p-4 rounded-lg border-2 cursor-pointer transition seed-mode-option
            {{ ($data['seed_mode'] ?? 'demo') === 'demo' ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300' }}"
            data-mode="demo">
            <input type="radio" name="seed_mode" value="demo" {{ ($data['seed_mode'] ?? 'demo') === 'demo' ? 'checked' : '' }} class="mt-0.5 text-blue-600" id="seed_demo">
            <div>
                <p class="text-sm font-semibold text-gray-800">Data Demo (Contoh)</p>
                <p class="text-xs text-gray-500 mt-1">Berisi contoh produk, brand, supplier, dan customer untuk belajar menggunakan aplikasi. Bisa dihapus nanti dari menu Reset Database.</p>
                <div class="mt-2 text-xs text-gray-400 grid grid-cols-2 gap-1">
                    <span>• 15 Brand (Indofood, Unilever, dll)</span>
                    <span>• 17 Produk dengan harga & barcode</span>
                    <span>• 6 Supplier dengan tempo</span>
                    <span>• 6 Customer + 1 Walk-in</span>
                    <span>• 4 Gudang</span>
                    <span>• 10 Metode Pembayaran</span>
                    <span>• 3 User demo (manager, kasir, gudang)</span>
                </div>
            </div>
        </label>

        <label class="flex items-start gap-3 p-4 rounded-lg border-2 cursor-pointer transition seed-mode-option
            {{ ($data['seed_mode'] ?? 'demo') === 'minimal' ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300' }}"
            data-mode="minimal">
            <input type="radio" name="seed_mode" value="minimal" {{ ($data['seed_mode'] ?? 'demo') === 'minimal' ? 'checked' : '' }} class="mt-0.5 text-blue-600" id="seed_minimal">
            <div>
                <p class="text-sm font-semibold text-gray-800">Mulai Kosong</p>
                <p class="text-xs text-gray-500 mt-1">Hanya data minimal yang dibutuhkan sistem. Anda akan mengisi semua data sendiri.</p>
                <div class="mt-2 text-xs text-gray-400">
                    <span>• 1 Gudang Utama • 1 Customer Walk-in • 3 Metode Pembayaran • Hanya akun admin Anda (tanpa user demo)</span>
                </div>
            </div>
        </label>
    </div>

    {{-- POS Terminal --}}
    <div id="terminalSection" class="border-t pt-5 mt-2">
        <label class="flex items-center gap-3 mb-4 cursor-pointer">
            <input type="checkbox" name="create_terminal" id="createTerminal" {{ old('create_terminal', $data['create_terminal'] ?? true) ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 w-5 h-5" onchange="toggleTerminalFields()">
            <div>
                <p class="text-sm font-semibold text-gray-700">Buat POS Terminal sekarang</p>
                <p class="text-xs text-gray-400">Agar bisa langsung mencoba fitur POS Kasir setelah instalasi selesai (mode demo maupun mulai kosong).</p>
            </div>
        </label>

        <div id="terminalFields" class="{{ old('create_terminal', $data['create_terminal'] ?? true) ? '' : 'hidden' }}">
                <div class="space-y-3 bg-gray-50 rounded-lg p-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Kode Terminal</label>
                            <input type="text" name="terminal_kode" value="{{ old('terminal_kode', $data['terminal_kode'] ?? 'KASIR_1') }}" pattern="[A-Za-z0-9_]+" title="Hanya huruf, angka, dan underscore" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <p class="text-xs text-gray-400 mt-0.5">Huruf, angka, underscore saja</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Nama Terminal</label>
                            <input type="text" name="terminal_nama" value="{{ old('terminal_nama', $data['terminal_nama'] ?? 'Kasir Utama') }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" name="terminal_izinkan_retur" {{ old('terminal_izinkan_retur', $data['terminal_izinkan_retur'] ?? true) ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600">
                                Izinkan Retur
                            </label>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Batas Retur</label>
                            <select name="terminal_durasi_retur" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="" {{ empty($data['terminal_durasi_retur']) ? 'selected' : '' }}>Unlimited</option>
                                <option value="0" {{ ($data['terminal_durasi_retur'] ?? '') === '0' ? 'selected' : '' }}>Shift ini saja</option>
                                <option value="1" {{ ($data['terminal_durasi_retur'] ?? '') === '1' ? 'selected' : '' }}>1 hari</option>
                                <option value="3" {{ ($data['terminal_durasi_retur'] ?? '') === '3' ? 'selected' : '' }}>3 hari</option>
                                <option value="7" {{ ($data['terminal_durasi_retur'] ?? '') === '7' ? 'selected' : '' }}>7 hari</option>
                            </select>
                        </div>
                    </div>
                    <p class="text-xs text-gray-400">Gudang, customer default (Walk-in), dan pembayaran default (Cash) akan diatur otomatis. User aktif akan ditugaskan ke terminal ini.</p>
                </div>
        </div>
    </div>

    <div class="flex justify-between pt-4">
        <a href="{{ route('installer.step7') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">&larr; Kembali</a>
        <button type="submit" class="px-6 py-2.5 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 transition">
            Mulai Instalasi &rarr;
        </button>
    </div>
</form>

@push('scripts')
<script>
function toggleTerminalFields() {
    document.getElementById('terminalFields').classList.toggle('hidden', !document.getElementById('createTerminal').checked);
}
document.querySelectorAll('input[name="seed_mode"]').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('.seed-mode-option').forEach(label => {
            const selected = label.dataset.mode === radio.value;
            label.classList.toggle('border-blue-500', selected);
            label.classList.toggle('bg-blue-50', selected);
            label.classList.toggle('border-gray-200', !selected);
            label.classList.toggle('hover:border-gray-300', !selected);
        });
    });
});
</script>
@endpush
@endsection
