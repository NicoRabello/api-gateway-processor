<?php

namespace App\Exporters;

use RuntimeException;

abstract class CsvExporter
{
    public function __construct(
        protected readonly CsvValueSanitizer $sanitizer,
    ) {}

    /**
     * @param  iterable<object>  $rows
     */
    final public function export(iterable $rows, string $path): string
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create output directory: {$directory}");
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV output file: {$path}");
        }

        try {
            if (fputcsv($handle, $this->headers()) === false) {
                throw new RuntimeException("Unable to write CSV header: {$path}");
            }

            foreach ($rows as $row) {
                if (fputcsv($handle, array_map(
                    fn (mixed $value): string => $this->sanitizer->sanitize($value),
                    $this->row($row),
                )) === false) {
                    throw new RuntimeException("Unable to write CSV row: {$path}");
                }
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    abstract protected function headers(): array;

    /**
     * @return list<mixed>
     */
    abstract protected function row(object $row): array;
}
