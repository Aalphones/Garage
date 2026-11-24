<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Stichtage eines Fahrzeugs (ADR-024): Kündigung, Sonderkündigung, Steuer- und Club-Fälligkeit, Plaketten, Garantien.
 * Gerechnet beim Lesen, nie gespeichert; nur Termine von heute bis heute + 24 Monate.
 */
final class DeadlineService
{
    public const KIND_NOTICE = 'kuendigung';
    public const KIND_SPECIAL_NOTICE = 'sonderkuendigung';
    public const KIND_DUE = 'faelligkeit';
    public const KIND_PERMIT = 'plakette';
    public const KIND_WARRANTY = 'garantie';

    public const STATUS_WARN = 'warn';
    public const STATUS_OK = 'ok';

    public const REF_CONTRACT = 'contract';
    public const REF_CONTRACT_EVENT = 'contract_event';
    public const REF_PERMIT = 'permit';
    public const REF_ACCESSORY = 'accessory';

    private const HORIZON_MONTHS = 24;
    private const WARN_DAYS = 30;
    private const NOTICE_KINDS = ['versicherung', 'club', 'stellplatz', 'sonstiges'];
    private const KIND_TAX = 'steuer';
    private const KIND_CLUB = 'club';
    private const EVENT_INVOICE = 'beitragsrechnung';
    private const EVENT_OPEN = 'offen';
    private const VEHICLE_ACTIVE = 'aktiv';
    private const LOCAL_TIMEZONE = 'Europe/Berlin';

    private VehicleAccess $access;
    private ContractRepository $contracts;
    private RecordRepository $records;

    public function __construct(PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->contracts = new ContractRepository($pdo);
        $this->records = new RecordRepository($pdo);
    }

    /**
     * Garagen-Verträge zählen nur bei aktiven Fahrzeugen; `level` sagt, ob der Termin am Fahrzeug oder an der Garage hängt
     * (das Kalender-Abo führt Garagen-Termine nur einmal).
     *
     * @return list<array{kind: string, title: string, date: string, status: string, days: int, explanation: string, level: string, ref: array{type: string, id: int}}>
     */
    public function forVehicle(int $vehicleId, Actor $actor): array
    {
        $vehicle = $this->access->readable($vehicleId, $actor);
        $today = self::today();
        $horizon = DueCalculator::addMonths($today, self::HORIZON_MONTHS);

        $contracts = array_values(array_filter(
            $this->contracts->forVehicle($vehicleId, $vehicle['garage_id'], $actor),
            static fn (array $contract): bool => $contract['vehicle_id'] !== null || $vehicle['status'] === self::VEHICLE_ACTIVE
        ));
        $contractIds = array_map(static fn (array $contract): int => (int) $contract['id'], $contracts);
        $terms = self::groupByContract($this->contracts->childrenOf('contract_terms', $contractIds, $actor));
        $events = self::groupByContract($this->contracts->childrenOf('contract_events', $contractIds, $actor));

        $deadlines = [];
        foreach ($contracts as $contract) {
            $contractId = (int) $contract['id'];
            $deadlines = [
                ...$deadlines,
                ...self::contractDeadlines($contract, $today),
                ...self::specialNotices($contract, $events[$contractId] ?? [], $terms[$contractId] ?? []),
            ];
        }
        foreach ($this->records->listForVehicle('permits', $vehicleId, $actor) as $permit) {
            if ($permit['valid_until'] !== null) {
                $date = (string) $permit['valid_until'];
                $deadlines[] = self::draft(self::KIND_PERMIT, $permit['label'] . ' läuft ab', $date, sprintf('Gültig bis %s.', DossierFormat::date($date)), null, self::REF_PERMIT, (int) $permit['id']);
            }
        }
        foreach ($this->records->listForVehicle('accessories', $vehicleId, $actor) as $accessory) {
            if ($accessory['warranty_until'] !== null) {
                $date = (string) $accessory['warranty_until'];
                $deadlines[] = self::draft(self::KIND_WARRANTY, 'Garantie ' . $accessory['name'] . ' endet', $date, sprintf('Garantie bis %s.', DossierFormat::date($date)), null, self::REF_ACCESSORY, (int) $accessory['id']);
            }
        }

        $inWindow = array_filter($deadlines, static fn (array $deadline): bool => $deadline['date'] >= $today && $deadline['date'] <= $horizon);
        $result = array_map(static fn (array $deadline): array => self::finish($deadline, $today), array_values($inWindow));
        usort($result, static fn (array $left, array $right): int => [$left['date'], $left['kind'], $left['ref']['id']] <=> [$right['date'], $right['kind'], $right['ref']['id']]);

        return $result;
    }

    /**
     * Kündigungsstichtag (Hauptfälligkeit − Frist − 1 Tag) und die Fälligkeit von Steuer und Club zur Hauptfälligkeit.
     *
     * @param array<string, mixed> $contract
     * @return list<array<string, mixed>>
     */
    private static function contractDeadlines(array $contract, string $today): array
    {
        if ($contract['main_due'] === null) {
            return [];
        }
        $mainDue = (string) $contract['main_due'];
        $endsOn = $contract['ends_on'] === null ? null : (string) $contract['ends_on'];
        $kind = (string) $contract['kind'];
        $provider = (string) $contract['provider'];
        $level = $contract['vehicle_id'] === null ? ContractService::LEVEL_GARAGE : ContractService::LEVEL_VEHICLE;
        $contractId = (int) $contract['id'];
        $deadlines = [];

        if (in_array($kind, self::NOTICE_KINDS, true) && $contract['notice_months'] !== null && $endsOn === null) {
            $months = (int) $contract['notice_months'];
            $due = self::nextMainDue($mainDue, $today);
            $noticeDate = self::noticeDate($due, $months);
            if ($noticeDate < $today) {
                $due = self::nextMainDue($mainDue, DueCalculator::addDays($due, 1));
                $noticeDate = self::noticeDate($due, $months);
            }
            $explanation = sprintf(
                'Hauptfälligkeit %s − %d %s Kündigungsfrist − 1 Tag = %s.',
                DossierFormat::date($due),
                $months,
                $months === 1 ? 'Monat' : 'Monate',
                DossierFormat::date($noticeDate)
            );
            $deadlines[] = self::draft(self::KIND_NOTICE, $provider . ': letzter Tag zur Kündigung', $noticeDate, $explanation, $level, self::REF_CONTRACT, $contractId);
        }

        if ($kind === self::KIND_TAX || $kind === self::KIND_CLUB) {
            $due = self::nextMainDue($mainDue, $today);
            if ($endsOn === null || $due <= $endsOn) {
                $title = $kind === self::KIND_TAX ? 'Kfz-Steuer fällig' : $provider . ': Beitrag fällig';
                $deadlines[] = self::draft(self::KIND_DUE, $title, $due, sprintf('Hauptfälligkeit jährlich am %s.', substr(DossierFormat::date($due), 0, 6)), $level, self::REF_CONTRACT, $contractId);
            }
        }

        return $deadlines;
    }

    /**
     * Sonderkündigung: eine offene Beitragsrechnung über dem Beitrag der Periode davor öffnet ein Kündigungsrecht von einem
     * Monat ab Zugang der Rechnung.
     *
     * @param array<string, mixed> $contract
     * @param list<array<string, mixed>> $events neueste zuerst
     * @param list<array<string, mixed>> $terms absteigend nach Beginn
     * @return list<array<string, mixed>>
     */
    private static function specialNotices(array $contract, array $events, array $terms): array
    {
        $deadlines = [];
        foreach ($events as $event) {
            $isOpenInvoice = $event['kind'] === self::EVENT_INVOICE && $event['event_status'] === self::EVENT_OPEN && $event['amount_cents'] !== null;
            if (!$isOpenInvoice) {
                continue;
            }
            $occurredOn = (string) $event['occurred_on'];
            $previous = null;
            foreach ($terms as $term) {
                if ((string) $term['valid_from'] < $occurredOn) {
                    $previous = $term;
                    break;
                }
            }
            if ($previous === null || (int) $event['amount_cents'] <= (int) $previous['amount_cents']) {
                continue;
            }
            $date = DueCalculator::addMonths($occurredOn, 1);
            $explanation = sprintf(
                'Beitragsrechnung vom %s über %s liegt über %s der Periode ab %s; Sonderkündigung bis einen Monat danach, %s.',
                DossierFormat::date($occurredOn),
                DossierFormat::euro(Money::centsToEuro((int) $event['amount_cents'])),
                DossierFormat::euro(Money::centsToEuro((int) $previous['amount_cents'])),
                DossierFormat::date((string) $previous['valid_from']),
                DossierFormat::date($date)
            );
            $level = $contract['vehicle_id'] === null ? ContractService::LEVEL_GARAGE : ContractService::LEVEL_VEHICLE;
            $deadlines[] = self::draft(self::KIND_SPECIAL_NOTICE, $contract['provider'] . ': Sonderkündigung nach Beitragserhöhung', $date, $explanation, $level, self::REF_CONTRACT_EVENT, (int) $event['id']);
        }

        return $deadlines;
    }

    /** Nächste Hauptfälligkeit `MM-TT` am oder nach `$onOrAfter`; der 29.02. wird in Nicht-Schaltjahren zum 28.02. */
    private static function nextMainDue(string $mainDue, string $onOrAfter): string
    {
        [$month, $day] = array_map('intval', explode('-', $mainDue));
        $year = (int) substr($onOrAfter, 0, 4);
        $candidate = self::dateIn($year, $month, $day);
        if ($candidate < $onOrAfter) {
            $candidate = self::dateIn($year + 1, $month, $day);
        }

        return $candidate;
    }

    private static function dateIn(int $year, int $month, int $day): string
    {
        $lastDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('UTC')))->format('t');

        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $lastDay));
    }

    private static function noticeDate(string $mainDue, int $noticeMonths): string
    {
        return DueCalculator::addDays(DueCalculator::addMonths($mainDue, -$noticeMonths), -1);
    }

    /** @return array<string, mixed> */
    private static function draft(string $kind, string $title, string $date, string $explanation, ?string $level, string $refType, int $refId): array
    {
        return [
            'kind' => $kind,
            'title' => $title,
            'date' => $date,
            'explanation' => $explanation,
            'level' => $level ?? ContractService::LEVEL_VEHICLE,
            'ref' => ['type' => $refType, 'id' => $refId],
        ];
    }

    /**
     * @param array<string, mixed> $draft
     * @return array{kind: string, title: string, date: string, status: string, days: int, explanation: string, level: string, ref: array{type: string, id: int}}
     */
    private static function finish(array $draft, string $today): array
    {
        $days = DueCalculator::daysBetween($today, $draft['date']);

        return [
            'kind' => $draft['kind'],
            'title' => $draft['title'],
            'date' => $draft['date'],
            'status' => $days <= self::WARN_DAYS ? self::STATUS_WARN : self::STATUS_OK,
            'days' => $days,
            'explanation' => $draft['explanation'],
            'level' => $draft['level'],
            'ref' => $draft['ref'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, list<array<string, mixed>>>
     */
    private static function groupByContract(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['contract_id']][] = $row;
        }

        return $grouped;
    }

    private static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TIMEZONE)))->format('Y-m-d');
    }
}
