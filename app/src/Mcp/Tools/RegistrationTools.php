<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\RegistrationService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class RegistrationTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var RegistrationService $registrations */
        $registrations = $services['registrations'];

        $registry->add(new Tool(
            'record_registration',
            'Zulassung erfassen',
            'Trägt eine Zulassung (Kennzeichen, Gültigkeit, Halter) ein. Dieselbe Zulassung (Kennzeichen + valid_from) wird nicht doppelt angelegt: ein zweiter Aufruf meldet unveraendert, zusätzliche Angaben ergaenzt, ein nicht bestätigter abweichender Wert aktualisiert. Weicht ein Wert von einem bestätigten ab, bleibt der bestätigte stehen und das Ergebnis ist klaerfall (open_item_ids nennt die Klärfälle, ein Mensch entscheidet). Ein Halterwechsel ist eine neue Zulassung mit neuem valid_from. Beispiel: {"vehicle_id": 12, "plate": "B-AB 1234", "valid_from": "2020-05-12", "holder": "Erika Mustermann", "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'plate' => Schema::text('Kennzeichen, 1 bis 15 Zeichen, z. B. "B-AB 1234" (wird in Großbuchstaben gespeichert)'),
                'valid_from' => Schema::text('Beginn der Zulassung, YYYY-MM-DD'),
                'valid_to' => Schema::text('Ende der Zulassung, YYYY-MM-DD, nicht vor valid_from; weglassen, wenn sie noch läuft'),
                'holder' => Schema::text('Halter laut Fahrzeugschein, 1 bis 120 Zeichen'),
                'holder_address' => Schema::text('Anschrift des Halters, 1 bis 200 Zeichen'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'plate', 'valid_from', 'source', 'confidence']),
            Tool::annotations('Zulassung erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($registrations->record((int) $args['vehicle_id'], $args, $actor))
        ));
    }
}
