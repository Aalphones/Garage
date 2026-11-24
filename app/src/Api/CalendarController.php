<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Auth\CalendarLink;
use Garage\Db\Connection;
use Garage\Domain\CalendarService;
use Garage\Domain\DomainError;
use Garage\Domain\GarageRepository;
use Garage\Http\Request;
use Garage\Http\Response;

/**
 * Kalender-Abo (ADR-024): Link lesen und erneuern über die angemeldete Oberfläche, `GET /garage/kalender/{slug}.ics` ohne
 * Anmeldung — die Signatur ist die Berechtigung.
 */
final class CalendarController
{
    private const FEED_HEADERS = [
        'Cache-Control' => 'private, max-age=3600',
        'X-Robots-Tag' => 'noindex',
        'Referrer-Policy' => 'no-referrer',
    ];
    private const MISS_HEADERS = ['X-Robots-Tag' => 'noindex'];

    public function link(Request $request, int $garageId): never
    {
        $actor = ApiAuth::user($request);

        try {
            $body = (new CalendarService(Connection::open()))->link($garageId, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($body);
    }

    public function rotate(Request $request, int $garageId): never
    {
        $actor = ApiAuth::user($request);

        try {
            $body = (new CalendarService(Connection::open()))->rotate($garageId, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($body);
    }

    /** Ungültig, alt oder unbekannt: 404 ohne Inhalt, damit sich gültige Garagen-IDs nicht erraten lassen. */
    public function feed(Request $request, string $slug): never
    {
        try {
            $pdo = Connection::open();
            $garageId = CalendarLink::verify($slug, new GarageRepository($pdo));
            if ($garageId === null) {
                Response::empty(404, self::MISS_HEADERS);
            }
            $body = (new CalendarService($pdo))->feed($garageId);
        } catch (DomainError $error) {
            Response::empty($error->errorCode === 'not_configured' ? 503 : 404, self::MISS_HEADERS);
        }

        Response::text($body, 'text/calendar; charset=utf-8', self::FEED_HEADERS);
    }
}
