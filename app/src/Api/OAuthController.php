<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\DomainError;
use Garage\Http\Request;
use Garage\Http\Response;
use Garage\OAuth\Consent;
use Garage\OAuth\TokenIssuer;

/** Aus DISPATCH (ADR-005). Web-API für die Zustimmung zu OAuth-Anfragen und die Liste der verbundenen Apps; nur mit der Anmeldung eines Nutzers, jeweils nur für ihn selbst. */
final class OAuthController
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function request(Request $request, string $slug): never
    {
        ApiAuth::user($request);

        try {
            Response::json((new Consent(Connection::open(), $this->config))->describe($slug));
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }
    }

    public function consent(Request $request): never
    {
        $actor = ApiAuth::user($request);

        try {
            $body = $request->json();
            $requestId = $body['request_id'] ?? null;
            $decision = $body['decision'] ?? null;
            $scopes = $body['scopes'] ?? [];
            if (!is_string($requestId)) {
                throw new DomainError('invalid_value', 'request_id fehlt.', ['field' => 'request_id']);
            }
            if ($decision !== 'allow' && $decision !== 'deny') {
                throw new DomainError('invalid_value', 'decision muss "allow" oder "deny" sein.', ['field' => 'decision']);
            }
            if (!is_array($scopes) || !array_is_list($scopes) || array_filter($scopes, 'is_string') !== $scopes) {
                throw new DomainError('invalid_value', 'scopes muss eine Liste von Texten sein.', ['field' => 'scopes']);
            }

            $redirect = (new Consent(Connection::open(), $this->config))->decide($requestId, $decision === 'allow', $scopes, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json(['redirect' => $redirect]);
    }

    public function grants(Request $request): never
    {
        $actor = ApiAuth::user($request);

        Response::json(['grants' => (new TokenIssuer(Connection::open(), $this->config))->grants($actor)]);
    }

    public function disconnect(Request $request, string $slug): never
    {
        $actor = ApiAuth::user($request);

        try {
            (new TokenIssuer(Connection::open(), $this->config))->disconnect($slug, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::empty(204);
    }
}
