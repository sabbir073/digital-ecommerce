<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV downloads for the admin's data exports. */
class Csv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $name, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map(self::safe(...), $row));
            }
            fclose($out);
        }, $name.'-'.date('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Text typed by customers can start with = + - or @, which a spreadsheet would run as a formula.
     * A leading apostrophe makes the spreadsheet show it as plain text.
     */
    public static function safe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)
            ? "'".$value
            : $value;
    }

    public static function money(int|string|null $cents): string
    {
        return number_format(((int) $cents) / 100, 2, '.', '');
    }
}
