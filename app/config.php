<?php

declare(strict_types=1);

use Garage\Util\Env;

return [
    // Adresse der Domain ohne Pfad, etwa https://quantum-canvas.de; die App liegt unter base_path (ADR-001).
    'base_url' => rtrim(Env::get('GARAGE_BASE_URL'), '/'),
    'base_path' => '/garage',
    'app_dir' => __DIR__,
    'storage_dir' => __DIR__ . '/storage',
];
