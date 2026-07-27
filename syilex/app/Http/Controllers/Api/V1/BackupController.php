<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class BackupController extends BaseApiController
{
    /**
     * Get database info for backup card.
     */
    public function info()
    {
        if (!auth()->user()->can('settings.reset')) {
            return $this->forbidden();
        }

        $dbName = config('database.connections.mysql.database');
        $tableCount = \DB::select('SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ?', [$dbName])[0]->cnt;

        $uploadsPath = storage_path('app/public');
        $uploadsSize = File::isDirectory($uploadsPath) ? $this->dirSize($uploadsPath) : 0;

        return $this->success([
            'database' => $dbName,
            'tables' => (int) $tableCount,
            'uploads_size_bytes' => $uploadsSize,
        ]);
    }

    /**
     * Download ZIP containing database dump + storage/app/public uploads.
     */
    public function download(Request $request)
    {
        if (!auth()->user()->can('settings.reset')) {
            return $this->forbidden();
        }

        $request->validate([
            'password' => 'required|string',
        ]);

        if (!Hash::check($request->password, auth()->user()->password)) {
            return $this->error('Password salah', 422);
        }

        if (! \function_exists('proc_open')) {
            return $this->error(
                'Fungsi proc_open dinonaktifkan di PHP server (disable_functions). Aktifkan proc_open di php.ini agar backup dapat dijalankan.',
                500
            );
        }

        $dumpBin = $this->findMysqldump();
        if (!$dumpBin) {
            return $this->error('mysqldump tidak ditemukan di server. Hubungi administrator.', 500);
        }

        $tmpDir = storage_path('app/tmp-backup-' . bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($tmpDir, 0755);

        try {
            // 1. Dump database to file
            $sqlPath = $tmpDir . DIRECTORY_SEPARATOR . 'database.sql';
            $dumpResult = $this->runMysqldump($dumpBin, $sqlPath);
            if ($dumpResult !== true) {
                File::deleteDirectory($tmpDir);
                return $this->error($dumpResult, 500);
            }

            // 2. Build ZIP with database.sql + storage/app/public files
            $zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'backup.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                File::deleteDirectory($tmpDir);
                return $this->error('Gagal membuat arsip backup', 500);
            }

            $zip->addFile($sqlPath, 'database.sql');

            $uploadsPath = storage_path('app/public');
            if (File::isDirectory($uploadsPath)) {
                $this->addDirToZip($zip, $uploadsPath, 'uploads');
            }

            $zip->close();

            // ZIP sudah final — pindahkan keluar tmp lalu hapus folder kerja.
            // BinaryFileResponse tidak punya setCallback(); deleteFileAfterSend hanya hapus file zip.
            $filename = 'posip_backup_' . date('Y-m-d_H-i-s') . '.zip';
            $downloadPath = storage_path('app' . DIRECTORY_SEPARATOR . $filename);
            File::move($zipPath, $downloadPath);
            File::deleteDirectory($tmpDir);

            return response()->download($downloadPath, $filename, [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            if (isset($tmpDir) && File::isDirectory($tmpDir)) {
                File::deleteDirectory($tmpDir);
            }
            return $this->error('Gagal membuat backup: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Restore from .zip (database + uploads) or legacy .sql (database only).
     */
    public function restore(Request $request)
    {
        if (!auth()->user()->can('settings.reset')) {
            return $this->forbidden();
        }

        $request->validate([
            'password' => 'required|string',
            'file' => 'required|file|max:2097152',
            'backup_acknowledged' => 'required|accepted',
        ], [
            'backup_acknowledged.required' => 'Konfirmasi backup wajib sebelum restore.',
            'backup_acknowledged.accepted' => 'Konfirmasi backup wajib sebelum restore.',
        ]);

        if (!Hash::check($request->password, auth()->user()->password)) {
            return $this->error('Password salah', 422);
        }

        if (! \function_exists('proc_open')) {
            return $this->error(
                'Fungsi proc_open dinonaktifkan di PHP server (disable_functions). Aktifkan proc_open di php.ini agar restore dapat dijalankan.',
                500
            );
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'zip') {
            return $this->restoreFromZip($file);
        }

        if ($ext === 'sql') {
            return $this->restoreFromSql($file->getRealPath());
        }

        return $this->error('File harus berformat .zip atau .sql', 422);
    }

    private function restoreFromZip($file)
    {
        $tmpDir = storage_path('app/tmp-restore-' . bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($tmpDir, 0755);

        try {
            $zip = new ZipArchive();
            if ($zip->open($file->getRealPath()) !== true) {
                File::deleteDirectory($tmpDir);
                return $this->error('File ZIP tidak valid atau rusak', 422);
            }

            $sqlEntry = $zip->locateName('database.sql');
            if ($sqlEntry === false) {
                $zip->close();
                File::deleteDirectory($tmpDir);
                return $this->error('Arsip tidak berisi database.sql', 422);
            }

            $entryCheck = $this->assertZipEntriesAllowed($zip);
            if ($entryCheck !== true) {
                $zip->close();
                File::deleteDirectory($tmpDir);
                return $this->error($entryCheck, 422);
            }

            $zip->extractTo($tmpDir);
            $zip->close();

            $sqlPath = $tmpDir . DIRECTORY_SEPARATOR . 'database.sql';
            $validation = $this->validateSqlFile($sqlPath);
            if ($validation !== true) {
                File::deleteDirectory($tmpDir);
                return $this->error($validation, 422);
            }

            $sqlResult = $this->importSql($sqlPath);
            if ($sqlResult !== true) {
                File::deleteDirectory($tmpDir);
                return $this->error('Gagal import database: ' . $sqlResult, 500);
            }

            // Wipe current uploads then restore from ZIP (avoid stale files)
            $uploadsTarget = storage_path('app/public');
            File::ensureDirectoryExists($uploadsTarget, 0755);
            File::cleanDirectory($uploadsTarget);

            $uploadsSource = $tmpDir . DIRECTORY_SEPARATOR . 'uploads';
            if (File::isDirectory($uploadsSource)) {
                File::copyDirectory($uploadsSource, $uploadsTarget);
            }

            File::deleteDirectory($tmpDir);
            SettingService::clearCache();

            return $this->success(null, 'Database + file upload berhasil direstore dari backup. Muat ulang aplikasi / login ulang agar settings ter-refresh.');
        } catch (\Throwable $e) {
            File::deleteDirectory($tmpDir);
            return $this->error('Gagal restore: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Allow only database.sql and uploads/** (no traversal, no other roots).
     * Cap uncompressed size per entry at 2GB.
     *
     * @return true|string
     */
    private function assertZipEntriesAllowed(ZipArchive $zip): true|string
    {
        $maxEntry = 2 * 1024 * 1024 * 1024;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($name === '' || str_contains($name, '..')) {
                return 'Arsip berisi path tidak aman';
            }
            $stat = $zip->statIndex($i);
            if (($stat['size'] ?? 0) > $maxEntry) {
                return 'Arsip berisi file terlalu besar';
            }
            if ($name === 'database.sql' || str_starts_with($name, 'uploads/')) {
                continue;
            }
            // Directory markers sometimes appear as "uploads"
            if ($name === 'uploads' || $name === 'uploads/') {
                continue;
            }

            return 'Arsip berisi file tidak diizinkan: ' . $name;
        }

        return true;
    }

    private function restoreFromSql(string $path)
    {
        $validation = $this->validateSqlFile($path);
        if ($validation !== true) {
            return $this->error($validation, 422);
        }

        $result = $this->importSql($path);
        if ($result !== true) {
            return $this->error('Gagal import database: ' . $result, 500);
        }

        SettingService::clearCache();

        return $this->success(null, 'Database berhasil direstore dari backup (mode legacy .sql, tanpa file upload). Muat ulang aplikasi / login ulang agar settings ter-refresh.');
    }

    /**
     * Run mysqldump to a target file path. Returns true on success, error string on failure.
     */
    private function runMysqldump(string $dumpBin, string $outputPath): true|string
    {
        $command = sprintf(
            '%s --host=%s --port=%s --user=%s --single-transaction --routines --triggers --add-drop-table %s',
            escapeshellarg($dumpBin),
            escapeshellarg(config('database.connections.mysql.host', '127.0.0.1')),
            escapeshellarg((string) config('database.connections.mysql.port', '3306')),
            escapeshellarg(config('database.connections.mysql.username')),
            escapeshellarg(config('database.connections.mysql.database'))
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outputPath, 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->scopedEnv();
        // Global builtins — must use \ prefix so PHP never resolves App\...\proc_open
        $process = \proc_open($command, $descriptors, $pipes, null, $env);

        if (! \is_resource($process)) {
            return 'Gagal menjalankan mysqldump';
        }

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = \proc_close($process);

        if ($exitCode !== 0) {
            $stderr = preg_replace('/.*password.*\n?/i', '', (string) $stderr);
            return 'mysqldump gagal (exit ' . $exitCode . '): ' . trim((string) $stderr);
        }

        return true;
    }

    /**
     * Import .sql file to active database. Returns true on success, error string on failure.
     */
    private function importSql(string $path): true|string
    {
        if (! \function_exists('proc_open')) {
            return 'Fungsi proc_open dinonaktifkan di PHP server (disable_functions)';
        }

        $mysqlBin = $this->findMysql();
        if (!$mysqlBin) {
            return 'mysql client tidak ditemukan di server';
        }

        $command = sprintf(
            '%s --host=%s --port=%s --user=%s %s',
            escapeshellarg($mysqlBin),
            escapeshellarg(config('database.connections.mysql.host', '127.0.0.1')),
            escapeshellarg((string) config('database.connections.mysql.port', '3306')),
            escapeshellarg(config('database.connections.mysql.username')),
            escapeshellarg(config('database.connections.mysql.database'))
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->scopedEnv();
        $process = \proc_open($command, $descriptors, $pipes, null, $env);

        if (! \is_resource($process)) {
            return 'Gagal menjalankan mysql client';
        }

        $fh = fopen($path, 'r');
        while (!feof($fh)) {
            $chunk = fread($fh, 8192);
            if ($chunk !== false) {
                fwrite($pipes[0], $chunk);
            }
        }
        fclose($fh);
        fclose($pipes[0]);

        stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = \proc_close($process);

        if ($exitCode !== 0) {
            $stderr = preg_replace('/.*password.*\n?/i', '', (string) $stderr);
            return trim((string) $stderr) ?: 'exit code ' . $exitCode;
        }

        return true;
    }

    /**
     * Return env array with MYSQL_PWD scoped (not mutating global $_ENV).
     */
    private function scopedEnv(): array
    {
        $base = getenv();
        $base['MYSQL_PWD'] = (string) config('database.connections.mysql.password');
        return $base;
    }

    private function addDirToZip(ZipArchive $zip, string $sourceDir, string $archivePrefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($sourceDir) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $zip->addFile($file->getPathname(), $archivePrefix . '/' . $relative);
        }
    }

    private function dirSize(string $path): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
    }

    private function validateSqlFile(string $path): true|string
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return 'Tidak dapat membaca file';
        }

        $header = fread($handle, 102400);
        fclose($handle);

        if (!$header) {
            return 'File kosong atau tidak dapat dibaca';
        }

        if (stripos($header, 'CREATE TABLE') === false && stripos($header, 'INSERT INTO') === false) {
            return 'File bukan SQL dump yang valid';
        }

        $posipTables = [
            'master_produk', 'master_brand', 'master_tipe', 'master_kategori',
            'master_grup', 'master_supplier', 'master_customer', 'master_warehouse',
            'master_metode_pembayaran', 'inventory_stock', 'stock_card',
            'doc_purchase_order', 'doc_sales', 'settings',
        ];

        $foundCount = 0;
        foreach ($posipTables as $table) {
            if (stripos($header, $table) !== false) {
                $foundCount++;
            }
        }

        if ($foundCount < 3) {
            return 'File bukan backup database POSIP yang valid. Tabel POSIP tidak ditemukan.';
        }

        return true;
    }

    private function findMysql(): ?string
    {
        return $this->findBinary('mysql', ['mysql', 'mariadb']);
    }

    private function findMysqldump(): ?string
    {
        return $this->findBinary('mysqldump', ['mysqldump', 'mariadb-dump']);
    }

    private function findBinary(string $name, array $aliases): ?string
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $exe = $isWindows ? "{$name}.exe" : $name;

        // 1. Check configurable path first (env or config)
        $configured = config('database.backup_bin.' . $name);
        if ($configured && is_string($configured) && $configured !== '' && file_exists($configured)) {
            return $configured;
        }

        // 2. Platform-specific common paths + Laragon version folders (any mysql-*)
        $candidates = [];
        if ($isWindows) {
            $laragonRoot = 'C:\\laragon\\bin\\mysql';
            if (is_dir($laragonRoot)) {
                foreach (glob($laragonRoot . '\\*\\bin\\' . $exe) ?: [] as $path) {
                    $candidates[] = $path;
                }
            }
            $candidates[] = "C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\{$exe}";
            $candidates[] = "C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\{$exe}";
        } else {
            $candidates = [
                "/usr/bin/{$name}",
                "/usr/local/bin/{$name}",
                "/usr/bin/mariadb" . ($name === 'mysqldump' ? '-dump' : ''),
                "/usr/local/bin/mariadb" . ($name === 'mysqldump' ? '-dump' : ''),
            ];
        }

        foreach ($candidates as $path) {
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        // 3. Fallback to PATH lookup
        foreach ($aliases as $alias) {
            $which = $isWindows ? "where {$alias} 2>NUL" : "which {$alias} 2>/dev/null";
            $result = trim((string) \shell_exec($which));
            if ($result) {
                $first = explode("\n", $result)[0];
                $first = trim($first);
                if ($first !== '' && file_exists($first)) {
                    return $first;
                }
            }
        }

        return null;
    }
}
