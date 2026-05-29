<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class ImportCheckpointRepository
{
    public function lastProcessedLine(string $sourceFileHash, string $firstLineHash): int
    {
        $checkpoint = DB::table('import_checkpoints')
            ->where('source_file_hash', $sourceFileHash)
            ->first(['first_line_hash', 'last_processed_line']);

        if ($checkpoint === null || $checkpoint->first_line_hash !== $firstLineHash) {
            return 0;
        }

        return (int) $checkpoint->last_processed_line;
    }

    public function update(string $sourceFileHash, string $firstLineHash, string $sourceFile, int $lineNumber): void
    {
        $now = now();
        $exists = DB::table('import_checkpoints')
            ->where('source_file_hash', $sourceFileHash)
            ->exists();

        if ($exists) {
            DB::table('import_checkpoints')
                ->where('source_file_hash', $sourceFileHash)
                ->update([
                    'first_line_hash' => $firstLineHash,
                    'source_file' => $sourceFile,
                    'last_processed_line' => $lineNumber,
                    'updated_at' => $now,
                ]);

            return;
        }

        DB::table('import_checkpoints')->insert([
            'source_file_hash' => $sourceFileHash,
            'first_line_hash' => $firstLineHash,
            'source_file' => $sourceFile,
            'last_processed_line' => $lineNumber,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
