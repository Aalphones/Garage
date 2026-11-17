<?php

declare(strict_types=1);

namespace Garage\Domain;

/** Geld liegt in Cent in der Datenbank und geht als Euro-Text mit zwei Nachkommastellen hinaus. */
final class Money
{
    public static function centsToEuro(int $cents): string
    {
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv($absolute, 100), $absolute % 100);
    }
}
