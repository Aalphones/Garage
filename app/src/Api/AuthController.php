<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Auth\UserSession;
use Garage\Db\Connection;
use Garage\Domain\DomainError;
use Garage\Http\Request;
use Garage\Http\Response;
use Garage\Util\Env;

/** Aus DISPATCH (ADR-005). Anmeldung der Nutzer unter `/garage/api/auth/` (ADR-006). */
final class AuthController
{
    public function login(Request $request): never
    {
        ApiAuth::requireConfiguration();

        try {
            $body = $request->json();
            $email = $body['email'] ?? null;
            $password = $body['password'] ?? null;
            if (!is_string($email) || !is_string($password) || $email === '' || $password === '') {
                throw new DomainError('invalid_credentials', 'E-Mail oder Passwort stimmt nicht.');
            }

            $result = $this->session()->login($email, $password, $request->ip());
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        self::respondWithSession($result);
    }

    public function refresh(Request $request): never
    {
        ApiAuth::requireConfiguration();
        $this->requireSameOrigin($request);

        try {
            $result = $this->session()->refresh($request->cookie(UserSession::COOKIE_NAME));
        } catch (DomainError $error) {
            Response::cookie(UserSession::COOKIE_NAME, '', 0, UserSession::COOKIE_PATH);
            ApiErrors::respond($error);
        }

        self::respondWithSession($result);
    }

    public function logout(Request $request): never
    {
        ApiAuth::requireConfiguration();
        $this->requireMarkerHeader($request);

        $this->session()->logout($request->cookie(UserSession::COOKIE_NAME));
        Response::cookie(UserSession::COOKIE_NAME, '', 0, UserSession::COOKIE_PATH);
        Response::empty(204);
    }

    public function logoutAll(Request $request): never
    {
        ApiAuth::requireConfiguration();
        $actor = ApiAuth::user($request);

        $this->session()->logoutAll((int) $actor->userId);
        Response::cookie(UserSession::COOKIE_NAME, '', 0, UserSession::COOKIE_PATH);
        Response::empty(204);
    }

    /**
     * Setzt den Cookie, wenn ein neuer Refresh-Token vorliegt, und antwortet ohne ihn im Body.
     *
     * @param array{access_token: string, expires_in: int, user: array{id: int, name: string, email: string}, refresh_token: string|null} $result
     */
    public static function respondWithSession(array $result): never
    {
        if ($result['refresh_token'] !== null) {
            Response::cookie(UserSession::COOKIE_NAME, $result['refresh_token'], UserSession::REFRESH_LIFETIME_SECONDS, UserSession::COOKIE_PATH);
        }
        Response::json([
            'access_token' => $result['access_token'],
            'expires_in' => $result['expires_in'],
            'user' => $result['user'],
        ]);
    }

    private function session(): UserSession
    {
        return new UserSession(Connection::open());
    }

    /** Schutz vor fremden Seiten: der Cookie ist SameSite=Strict, zusätzlich muss eine eigene Anfrage erkennbar sein. */
    private function requireSameOrigin(Request $request): void
    {
        $this->requireMarkerHeader($request);

        $parts = parse_url(Env::get('GARAGE_BASE_URL'));
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        if ($host === '' || $request->header('Origin') !== $scheme . '://' . $host . $port) {
            Response::error('forbidden_origin', 'Die Anfrage kommt nicht von dieser Website.', 403);
        }
    }

    private function requireMarkerHeader(Request $request): void
    {
        if ($request->header('X-Garage') !== '1') {
            Response::error('forbidden_origin', 'Der Header X-Garage: 1 fehlt.', 403);
        }
    }
}
