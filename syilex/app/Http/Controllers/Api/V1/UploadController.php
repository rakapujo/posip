<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class UploadController extends Controller
{
    /**
     * Folder-to-permission mapping.
     * Each upload folder requires a specific existing permission.
     */
    protected const FOLDER_PERMISSIONS = [
        'settings' => 'settings.update',
        'avatars' => 'user.update',
        'users' => 'user.update',
        'documents' => 'metode-bayar.update',
        'products' => 'produk.update',
        'payments' => 'metode-bayar.update',
    ];

    public function __construct(
        protected UploadService $uploadService
    ) {}

    /**
     * Upload an image
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file',
            'folder' => 'required|string',
            'old_path' => 'nullable|string',
        ]);

        $folder = $request->input('folder');

        // Check permission based on folder
        if (!$this->hasPermissionForFolder($folder)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        try {
            $result = $this->uploadService->uploadImage(
                $request->file('file'),
                $folder,
                $request->input('old_path')
            );

            return response()->json([
                'success' => true,
                'message' => 'File berhasil diupload',
                'data' => $result,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupload file: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a file
     */
    public function delete(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        // Check permission based on folder extracted from path
        $folder = $this->uploadService->extractFolderFromPath($path);
        if (!$folder || !$this->hasPermissionForFolder($folder)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        try {
            $deleted = $this->uploadService->deleteFile($path);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'File berhasil dihapus',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan',
            ], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus file: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available folders and their configurations
     */
    public function folders(): JsonResponse
    {
        $folders = [];
        foreach ($this->uploadService->getAvailableFolders() as $folder) {
            $folders[$folder] = $this->uploadService->getFolderInfo($folder);
        }

        return response()->json([
            'success' => true,
            'data' => $folders,
        ]);
    }

    /**
     * Check if current user has permission for the given folder.
     */
    protected function hasPermissionForFolder(string $folder): bool
    {
        $permission = self::FOLDER_PERMISSIONS[$folder] ?? null;

        if (!$permission) {
            return false;
        }

        return auth()->user()->can($permission);
    }
}
