<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;
use Intervention\Image\Encoders\WebpEncoder;
use InvalidArgumentException;

class UploadService
{
    /**
     * Upload and process an image
     *
     * @param UploadedFile $file
     * @param string $folder
     * @param string|null $oldPath Path to old file (will be deleted)
     * @return array{path: string, url: string, filename: string}
     */
    public function uploadImage(UploadedFile $file, string $folder, ?string $oldPath = null): array
    {
        // Get folder configuration
        $config = $this->getFolderConfig($folder);

        // Validate file
        $this->validateFile($file, $config);

        // Generate unique filename
        $filename = $this->generateFilename($file, $config);

        // Process and save image
        $path = $this->processAndSave($file, $folder, $filename, $config);

        // Delete old file if exists
        if ($oldPath) {
            $this->deleteFile($oldPath);
        }

        return [
            'path' => $path,
            'url' => Storage::disk($this->getDisk())->url($path),
            'filename' => $filename,
        ];
    }

    /**
     * Delete a file (with path traversal protection)
     *
     * @param string $path
     * @return bool
     */
    public function deleteFile(string $path): bool
    {
        $disk = Storage::disk($this->getDisk());

        // Handle both full URLs and relative paths
        $path = $this->extractPathFromUrl($path);

        // Validate path: block path traversal attempts
        if (str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            throw new InvalidArgumentException('Path tidak valid.');
        }

        // Validate that the file belongs to a whitelisted folder
        $folder = $this->extractFolderFromPath($path);
        if (!$folder) {
            throw new InvalidArgumentException('Folder file tidak valid.');
        }

        if ($disk->exists($path)) {
            return $disk->delete($path);
        }

        return false;
    }

    /**
     * Extract folder name from a file path and validate against whitelist.
     *
     * @param string $path
     * @return string|null Folder name if valid, null if not
     */
    public function extractFolderFromPath(string $path): ?string
    {
        $path = $this->extractPathFromUrl($path);

        // Block path traversal
        if (str_contains($path, '..')) {
            return null;
        }

        // Get first segment as folder name
        $segments = explode('/', str_replace('\\', '/', $path));
        $folder = $segments[0] ?? null;

        if (!$folder) {
            return null;
        }

        // Check against whitelisted folders
        $allowedFolders = array_keys(config('uploads.folders', []));
        if (!in_array($folder, $allowedFolders)) {
            return null;
        }

        return $folder;
    }

    /**
     * Get folder configuration
     *
     * @param string $folder
     * @return array
     */
    protected function getFolderConfig(string $folder): array
    {
        $folders = config('uploads.folders', []);

        if (!isset($folders[$folder])) {
            throw new InvalidArgumentException("Upload folder '{$folder}' is not configured.");
        }

        return array_merge([
            'max_width' => 1200,
            'max_height' => 1200,
            'max_size' => 5120,
            'allowed_types' => ['jpg', 'jpeg', 'png', 'webp'],
            'quality' => config('uploads.default_quality', 85),
        ], $folders[$folder]);
    }

    /**
     * Map extension ke expected MIME types — supaya rename evil.php → evil.jpg
     * tidak lolos (extension match tapi MIME real tidak match).
     */
    protected const MIME_MAP = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'gif'  => ['image/gif'],
    ];

    /**
     * Validate uploaded file
     *
     * @param UploadedFile $file
     * @param array $config
     * @return void
     */
    protected function validateFile(UploadedFile $file, array $config): void
    {
        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $config['allowed_types'])) {
            throw new InvalidArgumentException(
                'Tipe file tidak diizinkan. Tipe yang diizinkan: ' . implode(', ', $config['allowed_types'])
            );
        }

        // Check REAL MIME type (bukan cuma extension) — cegah rename trick.
        // getMimeType() baca magic number dari file actual, bukan filename.
        $realMime = $file->getMimeType();
        $expectedMimes = self::MIME_MAP[$extension] ?? [];
        if (!empty($expectedMimes) && !in_array($realMime, $expectedMimes)) {
            throw new InvalidArgumentException(
                "File extension .{$extension} tidak cocok dengan konten file. File tidak valid."
            );
        }

        // Check file size (in KB)
        $sizeInKb = $file->getSize() / 1024;
        if ($sizeInKb > $config['max_size']) {
            throw new InvalidArgumentException(
                'Ukuran file terlalu besar. Maksimal: ' . ($config['max_size'] / 1024) . 'MB'
            );
        }
    }

    /**
     * Generate unique filename
     *
     * @param UploadedFile $file
     * @param array $config
     * @return string
     */
    protected function generateFilename(UploadedFile $file, array $config): string
    {
        $format = config('uploads.default_format', 'webp');
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = Str::slug($originalName);
        $uniqueId = Str::ulid()->toBase32();

        return "{$safeName}-{$uniqueId}.{$format}";
    }

    /**
     * Process image and save to storage
     *
     * @param UploadedFile $file
     * @param string $folder
     * @param string $filename
     * @param array $config
     * @return string
     */
    protected function processAndSave(
        UploadedFile $file,
        string $folder,
        string $filename,
        array $config
    ): string {
        // Check if file is PDF (don't process, just store)
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'pdf') {
            return $file->storeAs($folder, $filename, $this->getDisk());
        }

        // Read image with Intervention
        $image = Image::read($file->getRealPath());

        // Smart resize - only downscale, maintain aspect ratio
        $image = $this->smartResize($image, $config['max_width'], $config['max_height']);

        // Convert to WebP and encode
        $quality = $config['quality'] ?? config('uploads.default_quality', 85);
        $encoded = $image->encode(new WebpEncoder($quality));

        // Save to storage
        $path = "{$folder}/{$filename}";
        Storage::disk($this->getDisk())->put($path, (string) $encoded);

        return $path;
    }

    /**
     * Smart resize image - only downscale, maintain aspect ratio
     *
     * @param \Intervention\Image\Interfaces\ImageInterface $image
     * @param int $maxWidth
     * @param int $maxHeight
     * @return \Intervention\Image\Interfaces\ImageInterface
     */
    protected function smartResize($image, int $maxWidth, int $maxHeight)
    {
        $width = $image->width();
        $height = $image->height();

        // Don't upscale - only resize if image is larger than max dimensions
        if ($width <= $maxWidth && $height <= $maxHeight) {
            return $image;
        }

        // Calculate new dimensions maintaining aspect ratio
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int) round($width * $ratio);
        $newHeight = (int) round($height * $ratio);

        return $image->resize($newWidth, $newHeight);
    }

    /**
     * Extract relative path from full URL
     *
     * @param string $path
     * @return string
     */
    protected function extractPathFromUrl(string $path): string
    {
        // If it's a full URL, extract the path
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $parsed = parse_url($path);
            $path = $parsed['path'] ?? $path;

            // Remove /storage/ prefix if present
            $path = preg_replace('#^/storage/#', '', $path);
        }

        return $path;
    }

    /**
     * Get storage disk name
     *
     * @return string
     */
    protected function getDisk(): string
    {
        return config('uploads.disk', 'public');
    }

    /**
     * Get available folders
     *
     * @return array
     */
    public function getAvailableFolders(): array
    {
        return array_keys(config('uploads.folders', []));
    }

    /**
     * Get folder config (public)
     *
     * @param string $folder
     * @return array|null
     */
    public function getFolderInfo(string $folder): ?array
    {
        $folders = config('uploads.folders', []);
        return $folders[$folder] ?? null;
    }
}
