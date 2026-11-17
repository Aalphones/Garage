<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\TireService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class TireTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var TireService $tires */
        $tires = $services['tires'];

        $registry->add(new Tool(
            'record_tires',
            'Reifensatz erfassen und montieren',
            'Legt einen Reifensatz an (label erkennt denselben Satz wieder) und montiert oder lagert ihn optional ein. mount {on, km?}: der Satz ist ab on montiert; die offene Montage eines anderen Satzes dieses Fahrzeugs endet am selben Tag automatisch. unmount {on, km?}: der Satz ist eingelagert; ohne offene Montage ist das ein Fehler (invalid_value). mount und unmount nicht zusammen. Ein zweiter identischer Aufruf meldet result unveraendert und mount.action unveraendert; Montage mit dem Datum einer vorhandenen Montage ändert nichts. mount.action ist montiert, eingelagert oder unveraendert. Der DOT-Code (Woche und Jahr, "2123" = Woche 21 in 2023) gehört zum Satz; get_tires rechnet das Alter aus. Ein km an der Montage ist Angabe zur Montage, kein Kilometerstand (der gehört in record_odometer). Beispiel: {"vehicle_id": 12, "set": {"label": "Sommer 16 Zoll", "brand": "Michelin", "size": "205/55 R16 91V", "season": "sommer", "dot": "2123"}, "mount": {"on": "2026-04-01", "km": 27500}, "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'set' => Schema::object([
                    'label' => Schema::text('Name des Satzes, 1 bis 80 Zeichen, z. B. "Sommer 16 Zoll"; erkennt denselben Satz wieder'),
                    'brand' => Schema::text('Hersteller, 1 bis 60 Zeichen'),
                    'model' => Schema::text('Reifentyp, 1 bis 80 Zeichen'),
                    'size' => Schema::text('Größe mit Last- und Geschwindigkeitsindex, 1 bis 30 Zeichen, z. B. "205/55 R16 91V"'),
                    'season' => Schema::choice(['sommer', 'winter', 'ganzjahr'], 'Einsatzzeit'),
                    'dot' => Schema::text('DOT-Code WWYY, vier Ziffern: Woche 01 bis 53 und Jahr, z. B. "2123"'),
                    'storage_location' => Schema::text('Lagerort, 1 bis 120 Zeichen, z. B. "Keller, Regal 2"'),
                ], ['label', 'size', 'season']),
                'mount' => self::movementSchema('Montage: der Satz ist ab diesem Tag am Fahrzeug'),
                'unmount' => self::movementSchema('Ausbau: der Satz wird an diesem Tag eingelagert'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'set', 'source', 'confidence']),
            Tool::annotations('Reifensatz erfassen und montieren', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($tires->record((int) $args['vehicle_id'], $args, $actor))
        ));

        $registry->add(new Tool(
            'get_tires',
            'Reifensätze lesen',
            'Liefert die Reifensätze eines Fahrzeugs: mounted_set_id (der montierte Satz oder null) und je Satz id, label, brand, model, size, season, dot, dot_age_years (volle Jahre seit der DOT-Woche), storage_location, mounts (mounted_on, mounted_km, removed_on, removed_km), source, confidence. Beispiel: {"vehicle_id": 12}.',
            Schema::object(['vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles')], ['vehicle_id']),
            Tool::annotations('Reifensätze lesen', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($tires->list((int) $args['vehicle_id'], $actor))
        ));
    }

    /** @return array<string, mixed> */
    private static function movementSchema(string $description): array
    {
        return Schema::object([
            'on' => Schema::text('Datum, YYYY-MM-DD'),
            'km' => Schema::integer('Kilometerstand dabei, 0 bis 2000000'),
        ], ['on']) + ['description' => $description];
    }
}
