<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\PrintLink;
use Garage\Auth\Scopes;
use Garage\Db\Connection;
use Garage\Domain\Actor;
use Garage\Domain\DomainError;
use Garage\Domain\DossierService;
use Garage\Domain\UserRepository;
use Garage\Http\Request;
use Garage\Http\Response;

/**
 * `GET /garage/akte/{slug}`: Druckansicht der Akte ohne Skript, ohne Anmeldung — die Signatur ist die Berechtigung (ADR-018).
 * Der Zugriff wird mit den heutigen Rollen des Nutzers aus dem Link geprüft; ein gesperrter Nutzer bekommt nichts.
 */
final class PrintController
{
    private const HEADERS = [
        'Cache-Control' => 'private, no-store',
        'X-Robots-Tag' => 'noindex',
        'Content-Security-Policy' => "default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",
        'Referrer-Policy' => 'no-referrer',
    ];

    public function show(Request $request, string $slug): never
    {
        try {
            $link = PrintLink::verify($slug);
            $pdo = Connection::open();
            $user = (new UserRepository($pdo))->findById($link['user_id']);
            if ($user === null || $user['status'] !== UserRepository::STATUS_ACTIVE) {
                throw new DomainError('not_found', 'Link ungültig.');
            }
            $actor = Actor::forUser($link['user_id'], Actor::VIA_WEB, 'user:' . $link['user_id'], [Scopes::READ], $pdo);
            $document = (new DossierService($pdo))->document($link['vehicle_id'], $actor);
        } catch (DomainError $error) {
            if ($error->errorCode === 'not_configured') {
                self::page('Drucken nicht möglich', 'Der Server ist nicht eingerichtet.', 503);
            }
            self::page('Link abgelaufen', 'Link abgelaufen — in GARAGE erneut auf Drucken tippen.', 404);
        }

        Response::html(self::renderTemplate($document['header'], $document['sections']), 200, self::HEADERS);
    }

    /**
     * @param array<string, mixed> $header
     * @param list<array{title: string, blocks: list<array<string, mixed>>}> $sections
     */
    private static function renderTemplate(array $header, array $sections): string
    {
        $e = self::escape(...);
        $rich = static fn (string $text): string => (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', self::escape($text));
        $origin = static fn (string $text): string => self::escape(substr($text, 1, -1));

        ob_start();
        require dirname(__DIR__, 2) . '/templates/akte-druck.php';

        return (string) ob_get_clean();
    }

    private static function page(string $title, string $content, int $status): never
    {
        $body = '<main class="paper"><p>' . self::escape($content) . '</p></main>';
        $html = sprintf(
            "<!doctype html>\n<html lang=\"de\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<meta name=\"robots\" content=\"noindex\">\n<title>%s · GARAGE</title>\n<link rel=\"stylesheet\" href=\"/garage/css/print.css\">\n</head>\n<body>\n%s\n</body>\n</html>\n",
            self::escape($title),
            $body
        );

        Response::html($html, $status, self::HEADERS);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
