<?php

namespace App\Domains\LeadSources\Drivers\CnpjBr;

use Generator;
use RuntimeException;
use SplFileObject;

/**
 * Reads the Receita dump CSVs (latin1, ';' separator, double quotes, no header).
 */
class ReceitaCsvReader
{
    /** @return Generator<int, list<string>> */
    public function rows(string $path): Generator
    {
        if (! is_readable($path)) {
            throw new RuntimeException("File not found or not readable: {$path}");
        }

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(';', '"', '\\');

        foreach ($file as $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }

            yield array_values(array_map(fn ($v) => $this->utf8(trim((string) $v)), $row));
        }
    }

    private function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }
}
