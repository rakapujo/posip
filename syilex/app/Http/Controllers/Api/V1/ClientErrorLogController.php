<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClientErrorLogController extends BaseApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required|string|max:2000',
            'source' => 'nullable|string|max:50',
            'url' => 'nullable|string|max:500',
            'stack' => 'nullable|string|max:5000',
            'component' => 'nullable|string|max:200',
            'user_agent' => 'nullable|string|max:500',
        ]);

        Log::channel('stack')->warning('Frontend error', [
            'user_id' => auth()->id(),
            'user_name' => auth()->user()?->name,
            'source' => $data['source'] ?? 'unknown',
            'message' => $data['message'],
            'url' => $data['url'] ?? null,
            'component' => $data['component'] ?? null,
            'stack' => $data['stack'] ?? null,
            'user_agent' => $data['user_agent'] ?? $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        return $this->success(null, 'Error logged');
    }
}
