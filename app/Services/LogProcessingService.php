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
        $checkpoint = $this->checkpointRepository->find($sourceFileHash, $firstLineHash);
        $file = new SplFileObject($realPath, 'rb');
        $prefixHash = hash_init('sha256');
        $lastProcessedLine = 0;
        $lineNumber = 0;

        if ($checkpoint !== null) {
            [$lastProcessedLine, $lineNumber, $prefixHash] = $this->resumeFromCheckpoint($file, $checkpoint);
        }

        $processed = 0;
        $inserted = 0;
        $ignored = 0;
        $invalid = 0;
        $batch = [];
        $byteOffset = $this->currentOffset($file);

        while (! $file->eof()) {
            $line = $file->fgets();

            if ($line === false || ($line === '' && $file->eof())) {
                break;
            }

            $lineNumber++;
            hash_update($prefixHash, $line);
            $byteOffset = $this->currentOffset($file);

            if ($lineNumber <= $lastProcessedLine) {
                if (trim($line) !== '') {
                    $ignored++;
                }

                continue;
            }

            if (trim($line) === '') {
                $processedPrefixHash = $this->currentHash($prefixHash);
                $this->flushBatch($batch, $sourceFileHash, $firstLineHash, $processedPrefixHash, $realPath, $byteOffset, $inserted, $ignored);
                $this->checkpointRepository->update($sourceFileHash, $firstLineHash, $processedPrefixHash, $realPath, $lineNumber, $byteOffset);

                continue;
            }

            $processed++;

            try {
                $log = $this->parser->parse($line, $realPath, $lineNumber);
            } catch (InvalidArgumentException|JsonException) {
                $invalid++;
                $processedPrefixHash = $this->currentHash($prefixHash);
                $this->flushBatch($batch, $sourceFileHash, $firstLineHash, $processedPrefixHash, $realPath, $byteOffset, $inserted, $ignored);
                $this->checkpointRepository->update($sourceFileHash, $firstLineHash, $processedPrefixHash, $realPath, $lineNumber, $byteOffset);

                continue;
            }

            $batch[] = $log;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->flushBatch($batch, $sourceFileHash, $firstLineHash, $this->currentHash($prefixHash), $realPath, $byteOffset, $inserted, $ignored);
            }
        }

        $this->flushBatch($batch, $sourceFileHash, $firstLineHash, $this->currentHash($prefixHash), $realPath, $byteOffset, $inserted, $ignored);

        return new LogProcessingResult($processed, $inserted, $ignored, $invalid);
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

    /**
     * @return array{0: int, 1: int, 2: resource}
     */
    private function resumeFromCheckpoint(SplFileObject $file, object $checkpoint): array
    {
        $lastProcessedLine = (int) $checkpoint->last_processed_line;
        $hash = hash_init('sha256');

        if ($lastProcessedLine <= 0) {
            return [0, 0, $hash];
        }

        $byteOffset = (int) ($checkpoint->last_processed_byte_offset ?? 0);
        $expectedHash = (string) ($checkpoint->processed_prefix_hash ?? '');

        if ($byteOffset > 0 && $expectedHash !== '' && $this->loadVerifiedPrefix($file, $hash, $byteOffset, $expectedHash)) {
            $file->fseek($byteOffset);
            $this->consumePendingLineEnding($file, $hash);

            return [$lastProcessedLine, $lastProcessedLine, $hash];
        }

        if ($byteOffset > 0 && $expectedHash !== '') {
            $file->rewind();

            return [0, 0, hash_init('sha256')];
        }

        $file->rewind();

        return $this->resumeLegacyLineCheckpoint($file, $hash, $lastProcessedLine);
    }

    /**
     * @param  resource  $hash
     * @return array{0: int, 1: int, 2: resource}
     */
    private function resumeLegacyLineCheckpoint(SplFileObject $file, $hash, int $lastProcessedLine): array
    {
        $file->rewind();
        $lineNumber = 0;

        while (! $file->eof() && $lineNumber < $lastProcessedLine) {
            $line = $file->fgets();

            if ($line === false || ($line === '' && $file->eof())) {
                break;
            }

            $lineNumber++;
            hash_update($hash, $line);
        }

        if ($lineNumber !== $lastProcessedLine) {
            $file->rewind();

            return [0, 0, hash_init('sha256')];
        }

        $this->consumePendingLineEnding($file, $hash);

        return [$lastProcessedLine, $lastProcessedLine, $hash];
    }

    /**
     * @param  resource  $hash
     */
    private function loadVerifiedPrefix(SplFileObject $file, $hash, int $byteOffset, string $expectedHash): bool
    {
        $fileSize = $file->getSize();

        if ($fileSize === false || $fileSize < $byteOffset) {
            return false;
        }

        $file->rewind();
        $remainingBytes = $byteOffset;

        while ($remainingBytes > 0 && ! $file->eof()) {
            $chunk = $file->fread(min(8192, $remainingBytes));

            if ($chunk === '') {
                break;
            }

            $remainingBytes -= strlen($chunk);
            hash_update($hash, $chunk);
        }

        if ($remainingBytes !== 0) {
            return false;
        }

        return $this->currentHash($hash) === $expectedHash;
    }

    private function currentOffset(SplFileObject $file): int
    {
        $offset = $file->ftell();

        return $offset === false ? 0 : $offset;
    }

    /**
     * @param  resource  $hash
     */
    private function consumePendingLineEnding(SplFileObject $file, $hash): void
    {
        $offset = $this->currentOffset($file);
        $firstByte = $file->fread(1);

        if ($firstByte === "\n") {
            hash_update($hash, $firstByte);

            return;
        }

        if ($firstByte === "\r") {
            hash_update($hash, $firstByte);

            $secondByte = $file->fread(1);

            if ($secondByte === "\n") {
                hash_update($hash, $secondByte);

                return;
            }
        }

        $file->fseek($offset);
    }

    /**
     * @param  resource  $hash
     */
    private function currentHash($hash): string
    {
        return hash_final(hash_copy($hash));
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
