<?php

namespace App\Console\Commands;

use App\Services\ReportGenerationService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class GenerateReportsCommand extends Command
{
    protected $signature = 'reports:generate {type? : consumers, services, or latencies} {--output=storage/app/reports : Output directory}';

    protected $aliases = ['reports'];

    protected $description = 'Generate API Gateway CSV reports from persisted logs.';

    public function handle(ReportGenerationService $service): int
    {
        try {
            $output = (string) $this->option('output');

            $type = $this->argument('type');
            $outputPath = $this->outputPath($output);

            $paths = $type === null
                ? $service->generateAll($outputPath)
                : [$service->generate((string) $type, $outputPath)];
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($paths as $path) {
            $this->info("Report generated: {$path}");
        }

        return self::SUCCESS;
    }

    private function outputPath(string $output): string
    {
        if (
            str_starts_with($output, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $output) === 1
            || str_contains($output, '..')
        ) {
            throw new InvalidArgumentException('Output directory must be a relative path inside the project.');
        }

        return base_path($output);
    }
}
