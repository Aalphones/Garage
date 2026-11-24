<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\ContractService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class ContractTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var ContractService $contracts */
        $contracts = $services['contracts'];

        $registry->add(new Tool(
            'upsert_contract',
            'Vertrag erfassen',
            'Legt einen Vertrag an oder ergänzt ihn: Versicherung, Kfz-Steuer, Club, Stellplatz, Finanzierung. Entweder vehicle_id (gilt für ein Fahrzeug) oder garage_id (gilt für alle aktiven Autos der Garage, etwa ADAC-Mitgliedschaft; Kosten werden nach allocation umgelegt: gleich, km oder fest) — genau eins von beiden. Art, Anbieter und Vertragsnummer erkennen denselben Vertrag wieder: ein zweiter identischer Aufruf meldet unveraendert, neue Angaben ergaenzt, ein Widerspruch zu einem bestätigten Wert klaerfall (open_item_ids). Beiträge je Periode trägt record_contract_term ein, Schäden, Rückstufungen und Kündigungen record_contract_event. main_due ist die Hauptfälligkeit als MM-TT (daraus folgt der Kündigungsstichtag mit notice_months). Beispiel Fahrzeug: {"vehicle_id": 12, "kind": "versicherung", "provider": "HUK-COBURG", "contract_number": "KH-4711", "tariff": "Classic XL", "main_due": "01-01", "notice_months": 1, "payment_interval": "jaehrlich", "source": "beleg", "confidence": "bestaetigt"}. Beispiel Garage: {"garage_id": 3, "kind": "club", "provider": "ADAC", "member_number": "123456789", "allocation": "gleich", "hotline": "+49 89 20 20 4000", "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles; nicht zusammen mit garage_id'),
                'garage_id' => Schema::integer('ID der Garage aus list_vehicles für einen Vertrag aller Autos; nicht zusammen mit vehicle_id'),
                'kind' => Schema::choice(ContractService::KINDS, 'Art des Vertrags'),
                'provider' => Schema::text('Anbieter, 1 bis 120 Zeichen, z. B. "HUK-COBURG", "Hauptzollamt", "ADAC"'),
                'contract_number' => Schema::text('Vertrags-, Steuer- oder Mitgliedsvertragsnummer, 1 bis 60 Zeichen'),
                'tariff' => Schema::text('Tarif, 1 bis 120 Zeichen'),
                'starts_on' => Schema::text('Vertragsbeginn YYYY-MM-DD'),
                'main_due' => Schema::text('Hauptfälligkeit MM-TT, z. B. "01-01"'),
                'notice_months' => Schema::integer('Kündigungsfrist in Monaten vor der Hauptfälligkeit, 0 bis 24'),
                'payment_interval' => Schema::choice(ContractService::PAYMENT_INTERVALS, 'Zahlweise'),
                'ends_on' => Schema::text('Vertragsende YYYY-MM-DD, nur wenn gekündigt oder befristet'),
                'allocation' => Schema::choice(ContractService::ALLOCATIONS, 'Nur bei garage_id: Umlage auf die aktiven Autos — gleich (Standard), km (nach gefahrenen km), fest (Anteile über record_contract_term)'),
                'hotline' => Schema::text('Notruf- oder Service-Nummer, 1 bis 40 Zeichen'),
                'member_number' => Schema::text('Mitglieds- oder Kundennummer, 1 bis 60 Zeichen'),
                'note' => Schema::text('Anmerkung, 1 bis 200 Zeichen'),
            ] + ProvenanceSchema::properties(), ['kind', 'provider', 'source', 'confidence']),
            Tool::annotations('Vertrag erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($contracts->upsert($args, $actor))
        ));

        $registry->add(new Tool(
            'record_contract_term',
            'Beitragsperiode erfassen',
            'Trägt den Beitrag eines Vertrags für einen Zeitraum ein (Beitragsrechnung, Steuerbescheid), dazu Schadenfreiheitsklassen, Typ- und Regionalklassen. Vertrag und Beginn erkennen dieselbe Periode wieder (unveraendert, ergaenzt, klaerfall wie bei upsert_contract). amount_eur ist der Beitrag für den ganzen Zeitraum valid_from bis valid_to. Bei einem Garagen-Vertrag mit allocation fest gibt allocations die Anteile je Fahrzeug ab valid_from an, zusammen genau 100. Beispiel: {"contract_id": 4, "valid_from": "2026-01-01", "valid_to": "2026-12-31", "amount_eur": "612.40", "sf_liability": "SF 12", "sf_comprehensive": "SF 8", "type_class_liability": 16, "regional_class": 7, "declared_km_per_year": 12000, "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'contract_id' => Schema::integer('ID des Vertrags aus upsert_contract oder get_papers'),
                'valid_from' => Schema::text('Beginn der Periode YYYY-MM-DD'),
                'valid_to' => Schema::text('Ende der Periode YYYY-MM-DD, nicht vor valid_from'),
                'amount_eur' => Schema::money('Beitrag der ganzen Periode in Euro, z. B. "612.40"'),
                'coverage' => Schema::text('Umfang, 1 bis 120 Zeichen, z. B. "Haftpflicht + Vollkasko"'),
                'sf_liability' => Schema::text('Schadenfreiheitsklasse Haftpflicht, z. B. "SF 12"'),
                'sf_comprehensive' => Schema::text('Schadenfreiheitsklasse Vollkasko, z. B. "SF 8"'),
                'type_class_liability' => Schema::integer('Typklasse Haftpflicht'),
                'type_class_partial' => Schema::integer('Typklasse Teilkasko'),
                'type_class_full' => Schema::integer('Typklasse Vollkasko'),
                'regional_class' => Schema::integer('Regionalklasse'),
                'declared_km_per_year' => Schema::integer('Angegebene Jahresfahrleistung in km'),
                'deductible_note' => Schema::text('Selbstbeteiligung, 1 bis 120 Zeichen, z. B. "VK 300 €, TK 150 €"'),
                'allocations' => Schema::list(
                    Schema::object([
                        'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                        'share_percent' => Schema::money('Anteil in Prozent, z. B. 60 oder "33.34"'),
                    ], ['vehicle_id', 'share_percent']),
                    'Nur bei Garagen-Verträgen mit allocation fest: Anteile je Fahrzeug ab valid_from, zusammen 100'
                ),
            ] + ProvenanceSchema::properties(), ['contract_id', 'valid_from', 'valid_to', 'amount_eur', 'source', 'confidence']),
            Tool::annotations('Beitragsperiode erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($contracts->recordTerm($args, $actor))
        ));

        $registry->add(new Tool(
            'record_contract_event',
            'Vorgang am Vertrag erfassen',
            'Trägt einen Vorgang an einem Vertrag ein: Beitragsrechnung, Änderung, Schadenmeldung, Regulierung, Rückstufung, Kündigung. Vertrag, Art und Datum erkennen denselben Vorgang wieder (unveraendert, ergaenzt, klaerfall wie bei upsert_contract). damage_id verknüpft einen Schaden aus get_damages, der zum Fahrzeug des Vertrags gehört; amount_eur darf negativ sein (Erstattung). Eine Beitragserhöhung als aenderung eintragen — sie öffnet ein Sonderkündigungsrecht. Beispiel: {"contract_id": 4, "kind": "schadenmeldung", "occurred_on": "2026-03-14", "claim_number": "S-2026-0815", "damage_id": 7, "event_status": "in_bearbeitung", "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'contract_id' => Schema::integer('ID des Vertrags aus upsert_contract oder get_papers'),
                'kind' => Schema::choice(ContractService::EVENT_KINDS, 'Art des Vorgangs'),
                'occurred_on' => Schema::text('Datum des Vorgangs YYYY-MM-DD'),
                'amount_eur' => Schema::money('Betrag in Euro, negativ für eine Erstattung'),
                'event_status' => Schema::choice(ContractService::EVENT_STATUSES, 'Stand des Vorgangs, Standard offen'),
                'claim_number' => Schema::text('Schaden- oder Vorgangsnummer, 1 bis 60 Zeichen'),
                'damage_id' => Schema::integer('ID eines Schadens aus get_damages'),
                'note' => Schema::text('Anmerkung, 1 bis 300 Zeichen'),
            ] + ProvenanceSchema::properties(), ['contract_id', 'kind', 'occurred_on', 'source', 'confidence']),
            Tool::annotations('Vorgang am Vertrag erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($contracts->recordEvent($args, $actor))
        ));
    }
}
