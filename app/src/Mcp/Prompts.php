<?php

declare(strict_types=1);

namespace Garage\Mcp;

/** Prompts (Slash-Befehle in Claude Code): feste Arbeitsanweisungen, die auf Tools und Leitfäden verweisen. */
final class Prompts
{
    private const ARGUMENT_MAX = 80;
    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/u';
    private const ANY_VEHICLE_RECORD = 'das passende Fahrzeug (list_vehicles)';
    private const ANY_VEHICLE_DUE = 'alle Fahrzeuge';

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return [
            [
                'name' => 'beleg_erfassen',
                'title' => 'Beleg erfassen',
                'description' => 'Zerlegt einen Beleg (Werkstattrechnung, Fahrzeugschein) aus dem Chat und trägt ihn nach Trockenlauf und Freigabe in die Akte ein.',
                'arguments' => [self::vehicleArgument('Fahrzeug, zu dem der Beleg gehört (Name oder Kennzeichen); ohne Angabe wählt der Agent über list_vehicles')],
            ],
            [
                'name' => 'was_steht_an',
                'title' => 'Was steht an?',
                'description' => 'Fasst zusammen, was überfällig oder bald fällig ist und welche Wartungsregeln unbelegt sind.',
                'arguments' => [self::vehicleArgument('Fahrzeug (Name oder Kennzeichen); ohne Angabe gelten alle Fahrzeuge')],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{description: string, messages: list<array{role: string, content: array{type: string, text: string}}>}
     */
    public function get(string $name, array $arguments): array
    {
        $vehicle = self::vehicleName($arguments);

        return match ($name) {
            'beleg_erfassen' => self::message('Beleg erfassen', self::recordText($vehicle)),
            'was_steht_an' => self::message('Was steht an?', self::dueText($vehicle)),
            default => throw new ProtocolError(
                ProtocolError::INVALID_PARAMS,
                sprintf('Prompt „%s“ ist unbekannt. prompts/list nennt die gültigen: beleg_erfassen, was_steht_an.', $name),
                400,
                ['name' => $name]
            ),
        };
    }

    /** @return array{name: string, description: string, required: bool} */
    private static function vehicleArgument(string $description): array
    {
        return ['name' => 'fahrzeug', 'description' => $description, 'required' => false];
    }

    /** @param array<string, mixed> $arguments */
    private static function vehicleName(array $arguments): ?string
    {
        $raw = $arguments['fahrzeug'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $isValid = is_string($raw) && mb_check_encoding($raw, 'UTF-8') && preg_match(self::CONTROL_CHARACTERS, $raw) !== 1 && mb_strlen(trim($raw)) <= self::ARGUMENT_MAX;
        if (!$isValid) {
            throw new ProtocolError(
                ProtocolError::INVALID_PARAMS,
                sprintf('Das Argument „fahrzeug“ ist ein einzeiliger Text mit höchstens %d Zeichen, z. B. "Golf 7".', self::ARGUMENT_MAX),
                400,
                ['argument' => 'fahrzeug']
            );
        }

        return trim($raw);
    }

    private static function recordText(?string $vehicle): string
    {
        $target = $vehicle === null ? self::ANY_VEHICLE_RECORD : $vehicle;

        return sprintf(
            'Erfasse den Beleg aus dem Chat für %s. Lies garage://leitfaden/erfassung, rufe validate_service_event, zeige mir das Ergebnis samt Klärfällen und rufe nach meinem Okay record_service_event.',
            $target
        );
    }

    private static function dueText(?string $vehicle): string
    {
        $target = $vehicle === null ? self::ANY_VEHICLE_DUE : sprintf('das Fahrzeug „%s“ (vehicle_id aus list_vehicles)', $vehicle);

        return sprintf(
            'Rufe get_due_items für %s und fasse zusammen: was ist überfällig, was kommt in 3 Monaten, welche Regeln sind unbelegt. Schätze Kosten nur, wenn das Scheckheft vergleichbare Ereignisse hat (list_events), und nenne die Quelle.',
            $target
        );
    }

    /** @return array{description: string, messages: list<array{role: string, content: array{type: string, text: string}}>} */
    private static function message(string $description, string $text): array
    {
        return ['description' => $description, 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]]];
    }
}
