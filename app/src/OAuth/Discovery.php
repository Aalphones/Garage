<?php

declare(strict_types=1);

namespace Garage\OAuth;

use Garage\Auth\Scopes;

/**
 * Discovery-Dokumente (RFC 9728, RFC 8414) und der `WWW-Authenticate`-Hinweis des MCP-Endpunkts; reine Funktionen ohne Datenbank.
 * Der Aussteller trägt den Pfad `/garage` (ADR-001): `base()` ist nur die Domain, `issuer()` die Domain samt Pfad.
 */
final class Discovery
{
    private const CHALLENGE_SCOPE = 'garage:read garage:write';
    private const RESOURCE_PATH = '/garage/mcp';
    private const METADATA_PATH = '/.well-known/oauth-protected-resource/garage/mcp';

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function protectedResource(array $config): array
    {
        return [
            'resource' => self::resource($config),
            'authorization_servers' => [self::issuer($config)],
            'scopes_supported' => Scopes::ALL,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'GARAGE',
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function authorizationServer(array $config): array
    {
        $issuer = self::issuer($config);

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'revocation_endpoint' => $issuer . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [...Scopes::ALL, 'offline_access'],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * Wert des `WWW-Authenticate`-Kopfs bei 401; `error` nur, wenn ein Token vorlag.
     *
     * @param array<string, mixed> $config
     */
    public static function challengeHeader(array $config, ?string $error): string
    {
        $parameters = [];
        if ($error !== null) {
            $parameters[] = sprintf('error="%s"', $error);
        }
        $parameters[] = sprintf('resource_metadata="%s"', self::base($config) . self::METADATA_PATH);
        $parameters[] = sprintf('scope="%s"', self::CHALLENGE_SCOPE);

        return 'Bearer ' . implode(', ', $parameters);
    }

    /** Nur die Domain, etwa `https://quantum-canvas.de`. */
    public static function base(array $config): string
    {
        return rtrim((string) ($config['base_url'] ?? ''), '/');
    }

    /** Aussteller der Tokens: Domain plus `base_path`. */
    public static function issuer(array $config): string
    {
        return self::base($config) . (string) ($config['base_path'] ?? '');
    }

    /** Adresse des MCP-Endpunkts; Tokens gelten nur für sie (`resource`, RFC 8707). */
    public static function resource(array $config): string
    {
        return self::base($config) . self::RESOURCE_PATH;
    }
}
