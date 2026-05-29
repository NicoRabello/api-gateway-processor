<?php

namespace App\Services;

use InvalidArgumentException;

class FilePathValidator
{
    public function validateReadableFile(string $path): string
    {
        if (str_contains($path, '..')) {
            throw new InvalidArgumentException('Path traversal is not allowed.');
        }

        $realPath = realpath($path);

        if ($realPath === false || ! is_file($realPath)) {
            throw new InvalidArgumentException('The informed log file does not exist.');
        }

        if (! is_readable($realPath)) {
            throw new InvalidArgumentException('The informed log file is not readable.');
        }

        return $realPath;
    }
}
