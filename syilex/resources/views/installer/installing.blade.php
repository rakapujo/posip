@extends('installer.layout')
@section('content')
<div class="text-center">
    <h2 class="text-lg font-semibold text-gray-800 mb-1">Menginstall POSIP...</h2>
    <p class="text-gray-500 text-sm mb-6">Mohon tunggu, proses ini membutuhkan beberapa saat.</p>
</div>

<div id="progress" class="space-y-2">
    <div class="flex items-center gap-2 text-sm text-gray-400">
        <svg class="w-4 h-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
        </svg>
        <span>Memulai instalasi...</span>
    </div>
</div>

<div id="errorBox" class="hidden mt-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"></div>

@push('scripts')
<script>
const progressEl = document.getElementById('progress');
const errorBox = document.getElementById('errorBox');
const doneUrl = @json(route('installer.done'));
const statusUrl = @json(route('installer.status'));
const runUrl = @json(route('installer.run'));
const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

function renderResults(results) {
    progressEl.innerHTML = '';
    (results || []).forEach(r => {
        const icon = r.status === 'ok'
            ? '<svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>'
            : '<svg class="w-4 h-4 text-red-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>';
        const color = r.status === 'ok' ? 'text-green-700' : 'text-red-700';
        progressEl.innerHTML += `<div class="flex items-center gap-2 py-1">${icon}<span class="text-sm ${color}">${r.step}</span></div>`;
    });
}

function showDoneLink() {
    progressEl.innerHTML += `<div class="mt-4 text-center"><a href="${doneUrl}" class="inline-block px-6 py-2.5 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 transition">Selesai &rarr;</a></div>`;
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function pollInstallStatus(maxAttempts = 60) {
    progressEl.innerHTML = `<div class="flex items-center gap-2 text-sm text-gray-500">
        <svg class="w-4 h-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
        </svg>
        <span>Server restart setelah instalasi — menunggu konfirmasi...</span>
    </div>`;

    for (let i = 0; i < maxAttempts; i++) {
        await sleep(2000);
        try {
            const res = await fetch(statusUrl, { credentials: 'same-origin' });
            if (!res.ok) continue;
            const data = await res.json();
            if (data.installed) {
                progressEl.innerHTML = `<div class="flex items-center gap-2 py-1 text-green-700 text-sm">
                    <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    <span>Instalasi berhasil!</span>
                </div>`;
                showDoneLink();
                return true;
            }
        } catch (_) {
            // Server may still be restarting
        }
    }
    return false;
}

async function runInstall() {
    try {
        const res = await fetch(runUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
        });

        if (!res.ok && res.status !== 500) {
            throw new Error('HTTP ' + res.status + ': ' + res.statusText);
        }

        const data = await res.json();
        renderResults(data.results || []);

        if (data.success) {
            showDoneLink();
            return;
        }

        errorBox.classList.remove('hidden');
        errorBox.textContent = 'Instalasi gagal: ' + (data.error || 'Unknown error');
    } catch (err) {
        const recovered = await pollInstallStatus();
        if (!recovered) {
            errorBox.classList.remove('hidden');
            errorBox.textContent = 'Koneksi error: ' + err.message + '. Instalasi mungkin belum selesai — refresh halaman atau cek storage/installed di server.';
        }
    }
}

runInstall();
</script>
@endpush
@endsection
