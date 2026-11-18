<?php

declare(strict_types=1);

namespace Garage\Mcp;

use Garage\Domain\Actor;
use Garage\Domain\DomainError;
use Garage\Domain\DossierService;
use Garage\Domain\DueService;
use Garage\Domain\VehicleService;

/** `garage://`-Resources: Fahrzeugliste, Akte und Fälligkeiten je Fahrzeug, zwei Leitfäden. Alles läuft über die Dienste und den Akteur des Tokens. */
final class Resources
{
    public const URI_WIDGET = 'ui://garage/beleg-ablegen';
    private const MIME_WIDGET = 'text/html;profile=mcp-app';
    private const URI_VEHICLES = 'garage://fahrzeuge';
    private const URI_GUIDE_RECORDING = 'garage://leitfaden/erfassung';
    private const URI_GUIDE_PROVENANCE = 'garage://leitfaden/herkunft';
    private const VEHICLE_URI = '#^garage://fahrzeug/([1-9][0-9]{0,9})/(akte|faelligkeiten)$#';
    private const MIME_JSON = 'application/json';
    private const MIME_MARKDOWN = 'text/markdown';
    private const DUE_HORIZON_MONTHS = DueService::DEFAULT_HORIZON_MONTHS;
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    private const GUIDE_FILES = [
        self::URI_GUIDE_RECORDING => 'leitfaden-erfassung.md',
        self::URI_GUIDE_PROVENANCE => 'leitfaden-herkunft.md',
    ];

    /** @param array<string, mixed> $services */
    public function __construct(private readonly array $services, private readonly Actor $actor)
    {
    }

    /** @return list<array<string, string>> */
    public function list(): array
    {
        return [
            ['uri' => self::URI_VEHICLES, 'name' => 'fahrzeuge', 'title' => 'Fahrzeuge', 'description' => 'Alle Garagen und Fahrzeuge des Nutzers (wie list_vehicles).', 'mimeType' => self::MIME_JSON],
            ['uri' => self::URI_GUIDE_RECORDING, 'name' => 'leitfaden-erfassung', 'title' => 'Leitfaden: Belege erfassen', 'description' => 'Wie eine Rechnung in Kopf, Positionen und Aufgabenschlüssel zerlegt und erfasst wird.', 'mimeType' => self::MIME_MARKDOWN],
            ['uri' => self::URI_GUIDE_PROVENANCE, 'name' => 'leitfaden-herkunft', 'title' => 'Leitfaden: Herkunft und Konfidenz', 'description' => 'Welche Quelle mit welcher Konfidenz eingetragen wird.', 'mimeType' => self::MIME_MARKDOWN],
            ['uri' => self::URI_WIDGET, 'name' => 'Beleg ablegen', 'title' => 'Beleg ablegen', 'description' => 'Ablagefläche für ein Foto oder PDF zu einem wartenden Beleg (Widget für store_document ohne Datei).', 'mimeType' => self::MIME_WIDGET],
        ];
    }

    /** @return list<array<string, string>> */
    public function templates(): array
    {
        return [
            ['uriTemplate' => 'garage://fahrzeug/{id}/akte', 'name' => 'fahrzeug-akte', 'title' => 'Fahrzeugakte', 'description' => 'Die ganze Akte eines Fahrzeugs als Markdown (wie get_dossier); {id} aus list_vehicles.', 'mimeType' => self::MIME_MARKDOWN],
            ['uriTemplate' => 'garage://fahrzeug/{id}/faelligkeiten', 'name' => 'fahrzeug-faelligkeiten', 'title' => 'Fälligkeiten eines Fahrzeugs', 'description' => 'Fällige Wartung der nächsten 36 Monate mit Rechenweg (wie get_due_items); {id} aus list_vehicles.', 'mimeType' => self::MIME_JSON],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function read(string $uri): array
    {
        if ($uri === self::URI_WIDGET) {
            return [$this->widget()];
        }
        if ($uri === self::URI_VEHICLES) {
            /** @var VehicleService $vehicles */
            $vehicles = $this->services['vehicles'];

            return [self::content($uri, self::MIME_JSON, json_encode(['garages' => $vehicles->list($this->actor)], self::JSON_FLAGS))];
        }
        if (isset(self::GUIDE_FILES[$uri])) {
            return [self::content($uri, self::MIME_MARKDOWN, self::guide(self::GUIDE_FILES[$uri]))];
        }
        if (preg_match(self::VEHICLE_URI, $uri, $match) === 1) {
            return [$this->readVehicle($uri, (int) $match[1], $match[2])];
        }

        throw self::unknown($uri);
    }

    /**
     * Das Widget „Beleg ablegen“. Die CSP erlaubt dem Rahmen nur Verbindungen zur eigenen Adresse (dorthin geht der Upload).
     *
     * @return array<string, mixed>
     */
    private function widget(): array
    {
        $baseUrl = rtrim((string) ((array) $this->services['config'])['base_url'], '/');
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $baseUrl);

        return self::content(self::URI_WIDGET, self::MIME_WIDGET, (string) file_get_contents(dirname(__DIR__, 2) . '/mcp/widget/beleg-ablegen.html'))
            + ['_meta' => ['ui' => ['csp' => ['connectDomains' => [$origin], 'resourceDomains' => []], 'prefersBorder' => true]]];
    }

    /** @return array{uri: string, mimeType: string, text: string} */
    private function readVehicle(string $uri, int $vehicleId, string $part): array
    {
        try {
            if ($part === 'akte') {
                /** @var DossierService $dossier */
                $dossier = $this->services['dossier'];

                return self::content($uri, self::MIME_MARKDOWN, $dossier->render($vehicleId, $this->actor));
            }
            /** @var DueService $due */
            $due = $this->services['due'];

            return self::content($uri, self::MIME_JSON, json_encode($due->dueItems($vehicleId, self::DUE_HORIZON_MONTHS, $this->actor), self::JSON_FLAGS));
        } catch (DomainError) {
            // Ein fremdes Fahrzeug darf sich nicht von einer unbekannten Adresse unterscheiden.
            throw self::unknown($uri);
        }
    }

    /** @return array{uri: string, mimeType: string, text: string} */
    private static function content(string $uri, string $mimeType, string $text): array
    {
        return ['uri' => $uri, 'mimeType' => $mimeType, 'text' => $text];
    }

    private static function guide(string $file): string
    {
        return trim((string) file_get_contents(dirname(__DIR__, 2) . '/mcp/' . $file)) . "\n";
    }

    // -32602 statt -32002: ab Protokoll 2026-07-28 ist -32002 für Resources verboten.
    private static function unknown(string $uri): ProtocolError
    {
        return new ProtocolError(
            ProtocolError::INVALID_PARAMS,
            sprintf('Resource „%s“ ist unbekannt. resources/list und resources/templates/list nennen die gültigen Adressen.', $uri),
            400,
            ['uri' => $uri]
        );
    }
}
