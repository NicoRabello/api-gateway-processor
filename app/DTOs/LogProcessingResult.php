<?php

namespace App\DTOs;

final readonly class LogProcessingResult
{
    public function __construct(
        public int $processed,
        public int $inserted,
        public int $ignored,
        public int $invalid,
        public float $durationSeconds,
        public int $peakMemoryBytes,
    ) {}

    public function throughputPerSecond(): float
    {
        if ($this->durationSeconds <= 0.0) {
            return 0.0;
        }

        return $this->processed / $this->durationSeconds;
    }
}
