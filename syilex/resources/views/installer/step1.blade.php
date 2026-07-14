@extends('installer.layout')
@section('content')
<h2 class="text-lg font-semibold text-gray-800 mb-1">Cek Server</h2>
<p class="text-gray-500 text-sm mb-6">Pastikan server memenuhi semua persyaratan sebelum melanjutkan.</p>

@if($errors->has('requirements'))
<div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
    {{ $errors->first('requirements') }}
</div>
@endif

<div class="space-y-2">
    @foreach($checks as $check)
    <div class="flex items-center justify-between py-2 px-3 rounded-lg {{ $check['passed'] ? 'bg-green-50' : 'bg-red-50' }}">
        <div class="flex items-center gap-2">
            @if($check['passed'])
                <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            @else
                <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
            @endif
            <span class="text-sm font-medium {{ $check['passed'] ? 'text-green-700' : 'text-red-700' }}">{{ $check['label'] }}</span>
        </div>
        <span class="text-xs {{ $check['passed'] ? 'text-green-600' : 'text-red-600' }}">{{ $check['value'] }}</span>
    </div>
    @if(!$check['passed'])
        <p class="text-xs text-red-500 ml-8 -mt-1">{{ $check['fix'] }}</p>
    @endif
    @endforeach
</div>

<form action="{{ route('installer.step1.post') }}" method="POST" class="mt-6 flex justify-end">
    @csrf
    <button type="submit" {{ !$allPassed ? 'disabled' : '' }}
        class="px-6 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition">
        Lanjut &rarr;
    </button>
</form>
@endsection
