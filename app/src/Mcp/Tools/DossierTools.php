<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\DossierService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class DossierTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var DossierService $dossier */
        $dossier = $services['dossier'];

        $registry->add(new Tool(
            'get_dossier',
            'Akte lesen',
            'Die ganze Akte als Markdown, Aufbau fest; für Export, Verkauf und als Kontext. Abschnitte in dieser Reihenfolge: Stammdaten, Zulassung, Ausstattung, Spezifikationen, Kilometerstand, Fälligkeiten, Wartungsplan, Scheckheft, Messwerte, Reifen, Teile, Schäden, Rückrufe, Notizen, Offene Klärfälle. Jeder Wert trägt seine Herkunft in Klammern, geschätzte Werte ein „≈“. Dieselbe Akte liefert die Resource garage://fahrzeug/{id}/akte. Beispiel: {"vehicle_id": 12}.',
            Schema::object(['vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles')], ['vehicle_id']),
            Tool::annotations('Akte lesen', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::markdown($dossier->render((int) $args['vehicle_id'], $actor))
        ));
    }
}
