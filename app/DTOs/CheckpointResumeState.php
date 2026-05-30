<?php

namespace App\DTOs;

final readonly class CheckpointResumeState
{
    /**
     * @param  resource  $prefixHash
     */
    public function __construct(
        public int $lastProcessedLine,
        public int $lineNumber,
        public mixed $prefixHash,
    ) {}
}
