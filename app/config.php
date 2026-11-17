<?php

declare(strict_types=1);

use Garage\Util\Env;

return [
    // Adresse der Domain ohne Pfad, etwa https://quantum-canvas.de; die App liegt unter base_path (ADR-001).
    'base_url' => rtrim(Env::get('GARAGE_BASE_URL'), '/'),
    'base_path' => '/garage',
    'app_dir' => __DIR__,
    'storage_dir' => __DIR__ . '/storage',
    // allow_private nur lokal (dev.env.example), auf Strato nie gesetzt.
    'http' => [
        'connect_timeout' => 5,
        'timeout' => 20,
        'max_redirects' => 4,
        'allow_private' => Env::get('GARAGE_ALLOW_PRIVATE_IPS') === '1',
    ],
    // OAuth 2.1 für KI-Apps, jede App per CIMD (E6, ADR-008).
    'oauth' => [
        'cimd_max_bytes' => 65536,
        'cimd_cache_seconds' => 86400,
        // Eigener HttpClient für CIMD; Claude wartet höchstens 10 s.
        'http' => ['connect_timeout' => 3, 'timeout' => 4, 'max_redirects' => 0],
        'request_seconds' => 600,               // Anfrage bis zur Zustimmung
        'code_seconds' => 300,
        'access_seconds' => 3600,
        'refresh_seconds' => 5184000,           // 60 Tage, bei jeder Erneuerung neu
        'grace_seconds' => 30,
        'default_scopes' => ['garage:read', 'garage:write', 'garage:files'],
    ],
    // Belege und Fotos (E1–E4, ADR-012): Grenzen der Ablage, Bildvarianten, Lebensdauer von Ticket und Link.
    'documents' => [
        'max_bytes' => 31457280,
        'max_megapixels' => 50,
        'max_edge' => 4000,
        'variant_widths' => [1568, 400],
        'jpeg_quality' => 90,
        'variant_quality' => 85,
        'ticket_seconds' => 900,
        'link_seconds' => 600,
    ],
];
