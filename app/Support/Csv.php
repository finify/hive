<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV downloads: UTF-8 with a byte order mark so Excel reads it, and cells
 * that would start a spreadsheet formula quoted.
 */
final class Csv
{
    /**
     * @param  iterable<array<int, mixed>>  $rows  the header row first
     */
    public static function download(string $filename, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '\\');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function cell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@]/', $value) === 1 && ! is_numeric($value) ? "'".$value : $value;
    }
}
