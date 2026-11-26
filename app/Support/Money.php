<?php

namespace App\Support;

use App\Services\StoreSettings;

/** All money is stored as whole cents (USD). */
class Money
{
    public static function format(int|string|null $cents): string
    {
        $cents = (int) $cents;
        $dollars = intdiv(abs($cents), 100);
        $rest = abs($cents) % 100;

        $amount = number_format($dollars).'.'.str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
        $sign = $cents < 0 ? '-' : '';

        // How prices are written is a store setting; the currency itself is always US dollars.
        return match (StoreSettings::currencyFormat()) {
            'us_symbol' => $sign.'US$'.$amount,
            'code' => $sign.$amount.' USD',
            default => $sign.'$'.$amount,
        };
    }

    /** "12.50" or "12" from an admin form or CSV to 1250. Null for an empty value. */
    public static function toCents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $clean = str_replace([',', 'US$', '$', 'USD', ' '], '', (string) $value);
        if (! is_numeric($clean)) {
            return null;
        }

        return (int) round(((float) $clean) * 100);
    }
}
