<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Domain\Provenance;
use Garage\Mcp\Schema;

/** Die drei Herkunftsfelder, die jedes schreibende Tool mit Angaben einmal je Aufruf auf oberster Ebene nimmt. */
final class ProvenanceSchema
{
    /** @return array<string, array<string, mixed>> */
    public static function properties(): array
    {
        return [
            'source' => Schema::choice(Provenance::SOURCES, 'Woher die Angaben stammen: beleg (Rechnung, Fahrzeugschein), nutzer (Aussage des Nutzers), recherche (Webseite), agent (eigene Ableitung), werkstatt'),
            'source_url' => Schema::text('Adresse der Quelle, z. B. "https://example.com/seite"; bei recherche Pflicht in der Praxis'),
            'confidence' => Schema::choice(array_keys(Provenance::CONFIDENCE_RANK), 'bestaetigt (Beleg liegt vor), wahrscheinlich, unsicher'),
        ];
    }
}
