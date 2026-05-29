<?php

namespace App\Exporters;

class CsvValueSanitizer
{
    public function sanitize(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        $trimmed = ltrim($text);

        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'".$text;
        }

        return $text;
    }
}
