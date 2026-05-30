<?php

namespace App\Console\Commands;

use App\Services\LogProcessingService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class ProcessLogsCommand extends Command
{
    protected $signature = 'logs:process {path : Path to the NDJSON log file}';

    protected $aliases = ['logs'];

    protected $description = 'Process API Gateway NDJSON logs incrementally.';

    public function handle(LogProcessingService $service): int
    {
        try {
            $result = $service->process((string) $this->argument('path'));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Log processing finished.');
        $this->line("Processed lines: {$result->processed}");
        $this->line("Inserted records: {$result->inserted}");
        $this->line("Ignored/skipped records: {$result->ignored}");
        $this->line("Invalid lines: {$result->invalid}");
        $this->line('Duration seconds: '.number_format($result->durationSeconds, 4, '.', ''));
        $this->line('Throughput lines/sec: '.number_format($result->throughputPerSecond(), 2, '.', ''));
        $this->line('Peak memory MB: '.number_format($result->peakMemoryBytes / 1024 / 1024, 2, '.', ''));

        return self::SUCCESS;
    }
}
