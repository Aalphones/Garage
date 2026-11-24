<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Auth\CalendarLink;
use Garage\Db\Transaction;
use PDO;

/**
 * Kalender-Abo je Garage (ADR-024): Link lesen und erneuern, Inhalt als iCalendar bauen. Der Inhalt sind Fälligkeiten mit
 * Datum und die Stichtage der aktiven Fahrzeuge, alles ganztägig.
 */
final class CalendarService
{
    private const UID_DOMAIN = '@garage';
    private const DUE_KIND = 'wartung';
    private const DUE_REF_TYPE = 'task';
    private const DUE_HORIZON_MONTHS = 36;
    private const CALENDAR_PREFIX = 'GARAGE – ';

    private GarageRepository $garages;
    private AuditRepository $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->garages = new GarageRepository($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /** @return array{url: string} */
    public function link(int $garageId, Actor $actor): array
    {
        $garage = $this->garages->find($garageId, $actor) ?? throw self::garageNotFound($garageId);

        return ['url' => CalendarLink::url($garageId, $garage['calendar_version'])];
    }

    /**
     * Neuer Link, der alte antwortet danach 404. Nur der Inhaber; fremde Garagen sind 404.
     *
     * @return array{url: string}
     */
    public function rotate(int $garageId, Actor $actor): array
    {
        if ($this->garages->find($garageId, $actor) === null) {
            throw self::garageNotFound($garageId);
        }

        return Transaction::run($this->pdo, function () use ($garageId, $actor): array {
            $version = $this->garages->bumpCalendarVersion($garageId, $actor);
            $this->audit->record($actor, 'calendar.rotate', sprintf('garages:%d', $garageId), $garageId, null, ['calendar_version' => $version]);

            return ['url' => CalendarLink::url($garageId, $version)];
        });
    }

    /** Inhalt des Abos; läuft ohne Nutzer mit einem Lese-Akteur nur für diese Garage. */
    public function feed(int $garageId): string
    {
        $actor = Actor::calendar($garageId);
        $garage = $this->garages->find($garageId, $actor) ?? throw self::garageNotFound($garageId);
        $vehicles = array_values(array_filter(
            (new VehicleRepository($this->pdo))->listForGarage($garageId, $actor),
            static fn (array $vehicle): bool => $vehicle['status'] === VehicleRepository::STATUS_ACTIVE
        ));
        $names = $this->displayNames($vehicles, $actor);
        $stamp = gmdate('Ymd\THis\Z');

        $events = [];
        $deadlines = new DeadlineService($this->pdo);
        foreach ($vehicles as $vehicle) {
            foreach ($deadlines->forVehicle($vehicle['id'], $actor) as $deadline) {
                $uid = sprintf('%s-%s-%d-%s%s', $deadline['kind'], $deadline['ref']['type'], $deadline['ref']['id'], $deadline['date'], self::UID_DOMAIN);
                // Garagen-Verträge stehen bei jedem aktiven Fahrzeug; im Kalender einmal, unter dem Namen der Garage.
                $owner = $deadline['level'] === ContractService::LEVEL_GARAGE ? $garage['name'] : $names[$vehicle['id']];
                $events[$uid] = Ical::event($uid, $deadline['date'], $owner . ': ' . $deadline['title'], $deadline['explanation'], $stamp);
            }
        }

        $dueItems = (new DueService($this->pdo))->dueItems(null, self::DUE_HORIZON_MONTHS, $actor)['items'];
        foreach ($dueItems as $item) {
            $hasDate = $item['effective_date'] !== null && $item['status'] !== DueCalculator::STATUS_UNKNOWN;
            if (!$hasDate || !isset($names[$item['vehicle_id']])) {
                continue;
            }
            $uid = sprintf('%s-%s-%d.%s-%s%s', self::DUE_KIND, self::DUE_REF_TYPE, $item['vehicle_id'], $item['task_key'], $item['effective_date'], self::UID_DOMAIN);
            $events[$uid] = Ical::event($uid, $item['effective_date'], $names[$item['vehicle_id']] . ': ' . $item['title'], $item['explanation'], $stamp);
        }

        return Ical::calendar(self::CALENDAR_PREFIX . $garage['name'], array_values($events));
    }

    /**
     * Gültiges Kennzeichen, sonst der Name des Fahrzeugs.
     *
     * @param list<array{id: int, label: string}> $vehicles
     * @return array<int, string>
     */
    private function displayNames(array $vehicles, Actor $actor): array
    {
        $registrations = new RegistrationService($this->pdo);
        $today = gmdate('Y-m-d');
        $names = [];
        foreach ($vehicles as $vehicle) {
            $names[$vehicle['id']] = $vehicle['label'];
            foreach ($registrations->list($vehicle['id'], $actor) as $registration) {
                if ($registration['valid_from'] <= $today && ($registration['valid_to'] === null || $registration['valid_to'] >= $today)) {
                    $names[$vehicle['id']] = $registration['plate'];
                    break;
                }
            }
        }

        return $names;
    }

    private static function garageNotFound(int $garageId): DomainError
    {
        return new DomainError('not_found', 'Eine Garage mit dieser ID gibt es für dich nicht.', ['garage_id' => $garageId]);
    }
}
