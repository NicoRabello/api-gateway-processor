<?php

namespace App\DTOs;

final readonly class LogProcessingResult
{
    public function __construct(
        public int $processed,
        public int $inserted,
        public int $ignored,
        public int $invalid,
    ) {}
}
