<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use stdClass;

class ImportCheckpointRepository
{
    public function find(string $sourceFileHash, string $firstLineHash): ?stdClass
    {
        $checkpoint = DB::table('import_checkpoints')
            ->where('source_file_hash', $sourceFileHash)
            ->first(['first_line_hash', 'last_processed_line', 'last_processed_byte_offset', 'processed_prefix_hash']);

        if ($checkpoint === null || $checkpoint->first_line_hash !== $firstLineHash) {
            return null;
        }

        return $checkpoint;
    }

    public function update(
        string $sourceFileHash,
        string $firstLineHash,
        string $processedPrefixHash,
        string $sourceFile,
        int $lineNumber,
        int $byteOffset,
    ): void {
        $now = now();

        DB::table('import_checkpoints')->upsert(
            [[
                'source_file_hash' => $sourceFileHash,
                'first_line_hash' => $firstLineHash,
                'processed_prefix_hash' => $processedPrefixHash,
                'source_file' => $sourceFile,
                'last_processed_line' => $lineNumber,
                'last_processed_byte_offset' => $byteOffset,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['source_file_hash'],
            ['first_line_hash', 'processed_prefix_hash', 'source_file', 'last_processed_line', 'last_processed_byte_offset', 'updated_at'],
        );
    }
}
