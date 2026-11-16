<?php

declare(strict_types=1);

// Brücke im ausgelieferten Bereich. Der Code liegt als Nachbarordner daneben
// (www/garage-app/, gesperrt durch dessen .htaccess) und wird per Dateizugriff eingebunden.
// Diese Datei landet auf dem Server unter www/garage/index.php (ADR-002).
require __DIR__ . '/../garage-app/index.php';
