<?php

namespace App\Services;

use App\DTOs\LogProcessingResult;
use App\DTOs\ParsedGatewayLog;
use App\Repositories\ImportCheckpointRepository;
use App\Repositories\ProcessedLogRepository;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SplFileObject;
use Throwable;

class LogProcessingService
{
    public function __construct(
        private readonly FilePathValidator $filePathValidator,
        private readonly CheckpointResumeService $checkpointResumeService,
        private readonly GatewayLogParser $parser,
        private readonly ProcessedLogRepository $repository,
        private readonly ImportCheckpointRepository $checkpointRepository,
    ) {}

    public function process(string $path): LogProcessingResult
    {
        $startedAt = microtime(true);
        $realPath = $this->filePathValidator->validateReadableFile($path);
        $batchSize = $this->batchSize();
        $sourceFileHash = hash('sha256', $realPath);
        $firstLineHash = $this->checkpointResumeService->firstLineHash($realPath);
        $checkpoint = $this->checkpointRepository->find($sourceFileHash, $firstLineHash);
        $file = new SplFileObject($realPath, 'rb');
        $resumeState = $this->checkpointResumeService->resume($file, $checkpoint);
        $prefixHash = $resumeState->prefixHash;
        $lastProcessedLine = $resumeState->lastProcessedLine;
        $lineNumber = $resumeState->lineNumber;

        $processed = 0;
        $inserted = 0;
        $ignored = 0;
        $invalid = 0;
        $batch = [];
        $byteOffset = $this->checkpointResumeService->currentOffset($file);

        while (! $file->eof()) {
            $line = $file->fgets();

            if ($line === false || ($line === '' && $file->eof())) {
                break;
            }

            $lineNumber++;
            hash_update($prefixHash, $line);
            $byteOffset = $this->checkpointResumeService->currentOffset($file);

            if ($lineNumber <= $lastProcessedLine) {
                if (trim($line) !== '') {
                    $ignored++;
                }

                continue;
            }

            if (trim($line) === '') {
                $processedPrefixHash = $this->checkpointResumeService->currentHash($prefixHash);
                $this->flushBatch($batch, $sourceFileHash, $firstLineHash, $processedPrefixHash, $realPath, $byteOffset, $inserted, $ignored);
                $this->checkpointRepository->update($sourceFileHash, $firstLineHash, $processedPrefixHash, $realPath, $lineNumber, $byteOffset);

                continue;
            }

            $processed++;

            try {
                $log = $this->parser->parse($line, $realPath, $lineNumber, $sourceFileHash);
            } catch (InvalidArgumentException|JsonException) {
                $invalid++;
                $processedPrefixHash = $this->checkpointResumeService->currentHash($prefixHash);
                $this->flushBatch($batch, $sourceFileHash, $firstLineHash, $processedPrefixHash, $realPath, $byteOffset, $inserted, $ignored);
                $this->checkpointRepository->update($sourceFileHash, $firstLineHash, $processedPrefixHash, $realPath, $lineNumber, $byteOffset);

                continue;
            }

            $batch[] = $log;

            if (count($batch) >= $batchSize) {
                $this->flushBatch($batch, $sourceFileHash, $firstLineHash, $this->checkpointResumeService->currentHash($prefixHash), $realPath, $byteOffset, $inserted, $ignored);
            }
        }

        $this->flushBatch($batch, $sourceFileHash, $firstLineHash, $this->checkpointResumeService->currentHash($prefixHash), $realPath, $byteOffset, $inserted, $ignored);

        return new LogProcessingResult(
            processed: $processed,
            inserted: $inserted,
            ignored: $ignored,
            invalid: $invalid,
            durationSeconds: microtime(true) - $startedAt,
            peakMemoryBytes: memory_get_peak_usage(true),
        );
    }

    private function batchSize(): int
    {
        return max(1, (int) config('log_processor.batch_size', 500));
    }

    /**
     * @param  list<ParsedGatewayLog>  $batch
     */
    private function flushBatch(
        array &$batch,
        string $sourceFileHash,
        string $firstLineHash,
        string $processedPrefixHash,
        string $realPath,
        int $byteOffset,
        int &$inserted,
        int &$ignored,
    ): void {
        if ($batch === []) {
            return;
        }

        $lastLineNumber = $batch[array_key_last($batch)]->lineNumber;

        try {
            $insertedInBatch = $this->repository->insertBatchIgnoringDuplicates($batch, now());
        } catch (Throwable $exception) {
            throw new RuntimeException("Failed to persist log batch ending at line {$lastLineNumber}.", previous: $exception);
        }

        $inserted += $insertedInBatch;
        $ignored += count($batch) - $insertedInBatch;

        $this->checkpointRepository->update($sourceFileHash, $firstLineHash, $processedPrefixHash, $realPath, $lastLineNumber, $byteOffset);
        $batch = [];
    }
}
