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
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly FilePathValidator $filePathValidator,
        private readonly GatewayLogParser $parser,
        private readonly ProcessedLogRepository $repository,
        private readonly ImportCheckpointRepository $checkpointRepository,
    ) {}

    public function process(string $path): LogProcessingResult
    {
        $realPath = $this->filePathValidator->validateReadableFile($path);
        $sourceFileHash = hash('sha256', $realPath);
        $firstLineHash = $this->firstLineHash($realPath);
        $lastProcessedLine = $this->checkpointRepository->lastProcessedLine($sourceFileHash, $firstLineHash);
        $file = new SplFileObject($realPath, 'rb');

        $processed = 0;
        $inserted = 0;
        $ignored = 0;
        $invalid = 0;
        $lineNumber = 0;
        $batch = [];

        while (! $file->eof()) {
            $line = $file->fgets();
            $lineNumber++;

            if ($lineNumber <= $lastProcessedLine) {
                if (trim($line) !== '') {
                    $ignored++;
                }

                continue;
            }

            if (trim($line) === '') {
                $this->flushBatch($batch, $sourceFileHash, $firstLineHash, $realPath, $inserted, $ignored);
                $this->checkpointRepository->update($sourceFileHash, $firstLineHash, $realPath, $lineNumber);

                continue;
            }

            $processed++;

            try {
                $log = $this->parser->parse($line, $realPath, $lineNumber);
            } catch (InvalidArgumentException|JsonException) {
                $invalid++;
                $this->flushBatch($batch, $sourceFileHash, $firstLineHash, $realPath, $inserted, $ignored);
                $this->checkpointRepository->update($sourceFileHash, $firstLineHash, $realPath, $lineNumber);

                continue;
            }

            $batch[] = $log;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->flushBatch($batch, $sourceFileHash, $firstLineHash, $realPath, $inserted, $ignored);
            }
        }

        $this->flushBatch($batch, $sourceFileHash, $firstLineHash, $realPath, $inserted, $ignored);

        return new LogProcessingResult($processed, $inserted, $ignored, $invalid);
    }

    /**
     * @param  list<ParsedGatewayLog>  $batch
     */
    private function flushBatch(
        array &$batch,
        string $sourceFileHash,
        string $firstLineHash,
        string $realPath,
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

        $this->checkpointRepository->update($sourceFileHash, $firstLineHash, $realPath, $lastLineNumber);
        $batch = [];
    }

    private function firstLineHash(string $path): string
    {
        $file = new SplFileObject($path, 'rb');

        while (! $file->eof()) {
            $line = trim($file->fgets());

            if ($line !== '') {
                return hash('sha256', $line);
            }
        }

        return hash('sha256', '');
    }
}
