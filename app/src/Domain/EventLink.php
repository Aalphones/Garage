<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/** Verweis eines Datensatzes auf ein Ereignis (z. B. Reparatur eines Schadens): das Ereignis muss zum selben Fahrzeug gehören. */
final class EventLink
{
    private EventRepository $events;

    public function __construct(PDO $pdo)
    {
        $this->events = new EventRepository($pdo);
    }

    /**
     * `null` bleibt `null`; ein fremdes oder unbekanntes Ereignis ist `not_found` wie überall.
     *
     * @return string|null die ID als Text, wie sie in die Spalte geschrieben und verglichen wird
     */
    public function validated(string $field, mixed $raw, int $vehicleId, Actor $actor): ?string
    {
        if ($raw === null) {
            return null;
        }
        $eventId = Validate::int($field, $raw, sprintf('{"%s": 31}', $field), 1);
        if (!$this->events->belongsToVehicle((int) $eventId, $vehicleId, $actor)) {
            throw new DomainError('not_found', sprintf('Feld „%s“: Ein Ereignis mit dieser ID gibt es an diesem Fahrzeug nicht. list_events zeigt die vorhandenen.', $field), ['event_id' => (int) $eventId]);
        }

        return $eventId;
    }
}
