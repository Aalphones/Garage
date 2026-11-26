<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\LoginThrottle;
use Garage\Domain\DomainError;
use Garage\Http\Response;

/** Übersetzt fachliche Fehler in den Status der Web-API; was hier fehlt, ist ein 400. */
final class ApiErrors
{
    private const STATUS_BY_CODE = [
        'invalid_json' => 400,
        'invalid_value' => 422,
        'invalid_credentials' => 401,
        'unauthorized' => 401,
        'session_expired' => 401,
        'session_revoked' => 401,
        'forbidden' => 403,
        'not_found' => 404,
        'invitation_not_found' => 404,
        'reset_not_found' => 404,
        'ticket_not_found' => 404,
        'ticket_used' => 410,
        'ticket_expired' => 410,
        'too_large' => 413,
        'type_rejected' => 415,
        'too_many_pixels' => 422,
        'store_failed' => 500,
        'not_configured' => 503,
        'already_resolved' => 409,
        'already_deleted' => 409,
        'not_deleted' => 409,
        'conflict' => 409,
        'open_item_pending' => 409,
        'restore_expired' => 410,
        'already_has_file' => 409,
        'email_taken' => 409,
        'last_owner' => 409,
        'already_member' => 409,
        'name_taken' => 409,
        'request_expired' => 410,
        'invitation_used' => 410,
        'invitation_expired' => 410,
        'reset_used' => 410,
        'reset_expired' => 410,
        'too_many_attempts' => 429,
    ];

    public static function respond(DomainError $error): never
    {
        $status = self::STATUS_BY_CODE[$error->errorCode] ?? 400;
        $headers = $status === 429 ? ['Retry-After' => (string) LoginThrottle::RETRY_AFTER_SECONDS] : [];

        Response::error($error->errorCode, $error->getMessage(), $status, $headers);
    }
}
