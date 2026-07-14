<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Instalasi POSIP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-3xl mx-auto py-8 px-4">
        {{-- Header --}}
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold text-gray-800">Instalasi POSIP</h1>
            <p class="text-gray-500 text-sm mt-1">Point of Sales — Setup Wizard</p>
        </div>

        {{-- Step Indicator --}}
        @isset($steps)
        <div class="flex items-center justify-center gap-1 mb-8 flex-wrap">
            @foreach($steps as $num => $label)
                <div class="flex items-center">
                    <div class="flex items-center gap-1.5 px-2 py-1 rounded-full text-xs font-medium
                        {{ $num < ($current ?? 0) ? 'bg-green-100 text-green-700' : '' }}
                        {{ $num === ($current ?? 0) ? 'bg-blue-600 text-white' : '' }}
                        {{ $num > ($current ?? 0) ? 'bg-gray-100 text-gray-400' : '' }}">
                        @if($num < ($current ?? 0))
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        @else
                            <span>{{ $num }}</span>
                        @endif
                        <span class="hidden sm:inline">{{ $label }}</span>
                    </div>
                    @if(!$loop->last)
                        <div class="w-4 h-px {{ $num < ($current ?? 0) ? 'bg-green-300' : 'bg-gray-200' }}"></div>
                    @endif
                </div>
            @endforeach
        </div>
        @endisset

        {{-- Errors --}}
        @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 text-sm">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
        @endif

        {{-- Content --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8">
            @yield('content')
        </div>

        <p class="text-center text-gray-400 text-xs mt-6">POSIP &copy; {{ date('Y') }}</p>
    </div>

    @stack('scripts')
</body>
</html>
