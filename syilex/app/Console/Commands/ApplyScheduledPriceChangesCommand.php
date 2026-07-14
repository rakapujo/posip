<?php

namespace App\Console\Commands;

use App\Actions\PriceChange\ApplyPriceChangeAction;
use App\Models\DocPriceChange;
use App\Models\PriceChangeTriggerLog;
use App\Services\SettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ApplyScheduledPriceChangesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'price-change:apply
                            {--force : Force run even if scheduler is disabled}
                            {--limit=50 : Maximum documents to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apply scheduled price changes that are due';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check if scheduler is enabled (unless forced)
        if (!$this->option('force') && !SettingService::isSchedulerEnabled('price_change')) {
            $this->warn('Price change scheduler is disabled. Use --force to override.');
            return Command::SUCCESS;
        }

        $limit = (int) $this->option('limit');

        $this->info('Checking for scheduled price changes...');

        // Get pending documents
        $pendingDocuments = DocPriceChange::pending()
            ->orderBy('tanggal_berlaku', 'asc')
            ->limit($limit)
            ->get();

        if ($pendingDocuments->isEmpty()) {
            $this->info('No pending price changes to apply.');
            return Command::SUCCESS;
        }

        $this->info("Found {$pendingDocuments->count()} document(s) to process.");

        $action = new ApplyPriceChangeAction();
        $processedCount = 0;
        $failedCount = 0;
        $documentNumbers = [];

        $progressBar = $this->output->createProgressBar($pendingDocuments->count());
        $progressBar->start();

        foreach ($pendingDocuments as $document) {
            try {
                if ($document->created_by) {
                    Auth::loginUsingId($document->created_by);
                }
                $action->execute($document, $document->created_by, 'cron');
                $processedCount++;
                $documentNumbers[] = $document->nomor_dokumen;
                $this->line(" <info>Applied:</info> {$document->nomor_dokumen}");
            } catch (\Exception $e) {
                $failedCount++;
                $this->line(" <error>Failed:</error> {$document->nomor_dokumen} - {$e->getMessage()}");

                Log::error('Failed to apply price change via command', [
                    'document_id' => $document->id,
                    'nomor_dokumen' => $document->nomor_dokumen,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Log the batch trigger
        if ($processedCount > 0) {
            PriceChangeTriggerLog::create([
                'triggered_at' => now(),
                'documents_processed' => $processedCount,
                'trigger_type' => 'cron',
                'triggered_by' => null,
                'notes' => "Cron applied: " . implode(', ', $documentNumbers),
            ]);

            Log::info('Applied scheduled price changes via command', [
                'count' => $processedCount,
                'documents' => $documentNumbers,
            ]);
        }

        // Summary
        $this->info("Summary:");
        $this->line("  - Processed: <info>{$processedCount}</info>");
        if ($failedCount > 0) {
            $this->line("  - Failed: <error>{$failedCount}</error>");
        }

        return $failedCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
