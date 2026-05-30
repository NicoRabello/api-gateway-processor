<?php

namespace App\Services;

use App\DTOs\CheckpointResumeState;
use SplFileObject;

class CheckpointResumeService
{
    public function firstLineHash(string $path): string
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

    public function resume(SplFileObject $file, ?object $checkpoint): CheckpointResumeState
    {
        if ($checkpoint === null) {
            return new CheckpointResumeState(0, 0, hash_init('sha256'));
        }

        $lastProcessedLine = (int) $checkpoint->last_processed_line;
        $hash = hash_init('sha256');

        if ($lastProcessedLine <= 0) {
            return new CheckpointResumeState(0, 0, $hash);
        }

        $byteOffset = (int) ($checkpoint->last_processed_byte_offset ?? 0);
        $expectedHash = (string) ($checkpoint->processed_prefix_hash ?? '');

        if ($byteOffset > 0 && $expectedHash !== '' && $this->loadVerifiedPrefix($file, $hash, $byteOffset, $expectedHash)) {
            $file->fseek($byteOffset);
            $this->consumePendingLineEnding($file, $hash);

            return new CheckpointResumeState($lastProcessedLine, $lastProcessedLine, $hash);
        }

        if ($byteOffset > 0 && $expectedHash !== '') {
            $file->rewind();

            return new CheckpointResumeState(0, 0, hash_init('sha256'));
        }

        $file->rewind();

        return $this->resumeLegacyLineCheckpoint($file, $hash, $lastProcessedLine);
    }

    public function currentOffset(SplFileObject $file): int
    {
        $offset = $file->ftell();

        return $offset === false ? 0 : $offset;
    }

    /**
     * @param  resource  $hash
     */
    public function currentHash($hash): string
    {
        return hash_final(hash_copy($hash));
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

    /**
     * @param  resource  $hash
     */
    private function resumeLegacyLineCheckpoint(SplFileObject $file, $hash, int $lastProcessedLine): CheckpointResumeState
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

            return new CheckpointResumeState(0, 0, hash_init('sha256'));
        }

        $this->consumePendingLineEnding($file, $hash);

        return new CheckpointResumeState($lastProcessedLine, $lastProcessedLine, $hash);
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
}
