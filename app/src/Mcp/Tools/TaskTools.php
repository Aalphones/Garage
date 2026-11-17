<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\DueService;
use Garage\Domain\TaskService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class TaskTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var TaskService $tasks */
        $tasks = $services['tasks'];
        /** @var DueService $due */
        $due = $services['due'];

        $registry->add(new Tool(
            'upsert_task_rule',
            'Wartungsaufgabe und Intervallregel eintragen',
            'Legt eine Wartungsaufgabe (task_key, z. B. "oelwechsel") mit ihrer Intervallregel an oder gleicht die Regel mit der gespeicherten ab. Eine Regel braucht interval_km, interval_months oder beides; „was zuerst eintritt“ gilt. first_km/first_months sind ein abweichendes Erstintervall, mode ist fest (Standard) oder flexibel (nach Bordanzeige, die Werte sind dann Höchstwerte). Beim Anlegen ist title Pflicht. Ergebnis: angelegt, aktualisiert (ersetzt eine nicht bestätigte Regel oder hebt die Konfidenz; die alte bleibt als abgelöst stehen), unveraendert oder klaerfall (eine bestätigte Regel bleibt stehen, die neue liegt als Vorschlag im Klärfall open_item_id, ein Mensch entscheidet). Die Regel gilt für die Fälligkeit aller Ereignisse, deren Positionen denselben task_key tragen (record_service_event). Beispiel: {"vehicle_id": 12, "task_key": "oelwechsel", "title": "Ölwechsel mit Filter", "interval_km": 15000, "interval_months": 24, "mode": "flexibel", "source": "werkstatt", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'task_key' => Schema::text('Aufgabenschlüssel: Kleinbuchstaben, Ziffern, Unterstrich, 2 bis 41 Zeichen, am Anfang ein Buchstabe, z. B. "oelwechsel"'),
                'title' => Schema::text('Anzeigename, 1 bis 120 Zeichen; Pflicht beim Anlegen, ein neuer Titel ersetzt den alten'),
                'interval_km' => Schema::integer('Intervall in Kilometern, 1 bis 1000000'),
                'interval_months' => Schema::integer('Intervall in Monaten, 1 bis 240'),
                'first_km' => Schema::integer('Erstintervall in Kilometern, falls es vom Intervall abweicht'),
                'first_months' => Schema::integer('Erstintervall in Monaten, falls es vom Intervall abweicht'),
                'mode' => Schema::choice(TaskService::MODES, 'fest (Standard) oder flexibel nach Bordanzeige'),
                'valid_from' => Schema::text('gilt ab, YYYY-MM-DD; Standard heute'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'task_key', 'source', 'confidence']),
            Tool::annotations('Wartungsaufgabe und Intervallregel eintragen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($tasks->upsertRule($args, $actor))
        ));

        $registry->add(new Tool(
            'get_due_items',
            'Fällige Wartung berechnen',
            'Berechnet beim Lesen, was an einem Fahrzeug (ohne vehicle_id: an allen deinen Fahrzeugen) wann fällig ist: aus der Intervallregel, der letzten Ausführung (Ereignis mit Position desselben task_key) und der Kilometerprognose, „was zuerst eintritt“. Je Aufgabe: due_date (aus Monaten), due_km, km_due_date (wann die Prognose diesen Stand erreicht), effective_date (das frühere von beiden), km_estimate, status (ueberfaellig, bald = innerhalb 3 Monaten oder 3000 km, ok, unbekannt = nicht berechenbar), rule_uncertain (true = Regel ohne Beleg, Konfidenz unsicher) und explanation mit dem Rechenweg. Sortiert: ueberfaellig, bald, unbekannt, ok. horizon_months (1 bis 120, Standard 36) begrenzt, wie weit voraus Aufgaben gelistet werden; überfällige, bald fällige und nicht berechenbare stehen immer dabei. Beispiel: {"vehicle_id": 12, "horizon_months": 12}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles; ohne Angabe alle Fahrzeuge'),
                'horizon_months' => Schema::integer('Wie viele Monate voraus, 1 bis 120, Standard 36'),
            ]),
            Tool::annotations('Fällige Wartung berechnen', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($due->dueItems(
                isset($args['vehicle_id']) ? (int) $args['vehicle_id'] : null,
                isset($args['horizon_months']) ? (int) $args['horizon_months'] : DueService::DEFAULT_HORIZON_MONTHS,
                $actor
            ))
        ));
    }
}
