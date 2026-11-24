<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\AccessoryService;
use Garage\Domain\Actor;
use Garage\Domain\PaperService;
use Garage\Domain\PermitService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class PaperTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var AccessoryService $accessories */
        $accessories = $services['accessories'];
        /** @var PermitService $permits */
        $permits = $services['permits'];
        /** @var PaperService $papers */
        $papers = $services['papers'];

        $registry->add(new Tool(
            'upsert_accessory',
            'Zubehör erfassen',
            'Trägt Zubehör eines Fahrzeugs ein: Träger, Kindersitz, Matten, Dashcam, Kabel. Der Name erkennt dasselbe Stück wieder: ein zweiter identischer Aufruf meldet unveraendert, neue Angaben ergaenzt, ein Widerspruch zu einem bestätigten Wert klaerfall (open_item_ids). goes_with_sale: geht beim Verkauf mit. Beispiel: {"vehicle_id": 12, "name": "Thule EasyFold XT 2", "category": "traeger", "manufacturer": "Thule", "bought_on": "2024-05-02", "price_eur": "649.00", "warranty_until": "2026-05-02", "mount_state": "eingelagert", "storage_location": "Keller", "goes_with_sale": false, "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'name' => Schema::text('Bezeichnung, 1 bis 120 Zeichen'),
                'category' => Schema::choice(AccessoryService::CATEGORIES, 'Art des Zubehörs'),
                'manufacturer' => Schema::text('Hersteller, 1 bis 80 Zeichen'),
                'article_number' => Schema::text('Artikelnummer, 1 bis 60 Zeichen'),
                'bought_on' => Schema::text('Kaufdatum YYYY-MM-DD'),
                'price_eur' => Schema::money('Kaufpreis in Euro'),
                'warranty_until' => Schema::text('Garantie bis YYYY-MM-DD'),
                'mount_state' => Schema::choice(AccessoryService::MOUNT_STATES, 'Am Auto montiert oder eingelagert'),
                'storage_location' => Schema::text('Lagerort, 1 bis 120 Zeichen'),
                'goes_with_sale' => Schema::boolean('Geht beim Verkauf mit dem Auto mit'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'name', 'category', 'source', 'confidence']),
            Tool::annotations('Zubehör erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($accessories->upsert((int) $args['vehicle_id'], $args, $actor))
        ));

        $registry->add(new Tool(
            'upsert_permit',
            'Plakette erfassen',
            'Trägt eine Plakette oder einen Ausweis eines Fahrzeugs ein: Umweltplakette, Crit\'Air, Vignette, Parkausweis, Mautbox. Art und Bezeichnung erkennen dieselbe wieder (unveraendert, ergaenzt, klaerfall wie bei upsert_accessory); eine neue Jahresvignette bekommt eine neue Bezeichnung. valid_until ergibt einen Stichtag. Beispiel: {"vehicle_id": 12, "kind": "vignette", "label": "Österreich 2026", "valid_from": "2025-12-01", "valid_until": "2027-01-31", "price_eur": "103.80", "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'kind' => Schema::choice(PermitService::KINDS, 'Art der Plakette oder des Ausweises'),
                'label' => Schema::text('Bezeichnung, 1 bis 80 Zeichen, z. B. "Grün (4)", "Österreich 2026", "Anwohner Zone 3"'),
                'number' => Schema::text('Nummer, 1 bis 60 Zeichen'),
                'valid_from' => Schema::text('Gültig ab YYYY-MM-DD'),
                'valid_until' => Schema::text('Gültig bis YYYY-MM-DD'),
                'price_eur' => Schema::money('Preis in Euro'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'kind', 'label', 'source', 'confidence']),
            Tool::annotations('Plakette erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($permits->upsert((int) $args['vehicle_id'], $args, $actor))
        ));

        $registry->add(new Tool(
            'get_papers',
            'Papiere lesen',
            'Liefert die Papiere eines Fahrzeugs: contracts (eigene Verträge mit level fahrzeug, dann die der Garage mit level garage; je mit terms und events, neueste zuerst; Garagen-Verträge mit share: method, percent und eur_per_year = Anteil dieses Fahrzeugs heute), deadlines (Stichtage), permits, accessories und emergency (Notfalldaten). Beispiel: {"vehicle_id": 12}.',
            Schema::object(['vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles')], ['vehicle_id']),
            Tool::annotations('Papiere lesen', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($papers->forVehicle((int) $args['vehicle_id'], $actor))
        ));
    }
}
