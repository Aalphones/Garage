<?php

declare(strict_types=1);

namespace Garage\OAuth;

use Garage\Http\Response;

/** Aus DISPATCH (ADR-005). `/.well-known/oauth-*`: öffentlich, ohne Datenbankzugriff, fünf Minuten zwischenspeicherbar. */
final class DiscoveryController
{
    private const CACHE_CONTROL = 'public, max-age=300';

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function protectedResource(): never
    {
        Response::json(Discovery::protectedResource($this->config), 200, ['Cache-Control' => self::CACHE_CONTROL]);
    }

    public function authorizationServer(): never
    {
        Response::json(Discovery::authorizationServer($this->config), 200, ['Cache-Control' => self::CACHE_CONTROL]);
    }
}
