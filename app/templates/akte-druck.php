<?php

declare(strict_types=1);

/**
 * Druckansicht der Akte (ADR-018): reines HTML ohne Skript, Stil aus `/garage/css/print.css`.
 *
 * @var array<string, mixed> $header Kopfdaten aus `DossierService::document`
 * @var list<array{title: string, blocks: list<array<string, mixed>>}> $sections
 * @var callable(string): string $e Text maskieren
 * @var callable(string): string $rich Text maskieren, `**fett**` als Hervorhebung
 * @var callable(string): string $origin Herkunft ohne Klammern, maskiert
 */

use Garage\Domain\DossierFormat;

$facts = [];
$facts[] = $header['plate'] ?? '–';
$facts[] = 'EZ ' . DossierFormat::date($header['first_registration']);
$facts[] = 'Stand ' . DossierFormat::date($header['today']);
$facts[] = $header['km'] === null ? 'kein Kilometerstand' : ($header['km_estimated'] ? DossierFormat::APPROXIMATE . ' ' : '') . DossierFormat::number($header['km']) . ' km' . ($header['km_estimated'] ? ' (geschätzt)' : '');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e($header['label']) ?> · Akte · GARAGE</title>
<link rel="stylesheet" href="/garage/css/print.css">
</head>
<body>
<main class="paper">
<p class="hint no-print">Mit Strg+P oder Teilen → Drucken als PDF speichern.</p>
<header>
<h1><?= $e($header['label']) ?></h1>
<p class="facts"><?= $e(implode(' · ', $facts)) ?></p>
</header>
<?php foreach ($sections as $section) : ?>
<section>
<h2><?= $e($section['title']) ?></h2>
<?php if ($section['blocks'] === []) : ?>
<p class="empty"><?= $e(DossierFormat::EMPTY_SECTION) ?></p>
<?php endif; ?>
<?php foreach ($section['blocks'] as $block) : ?>
<?php if ($block['kind'] === 'table') : ?>
<?php if ($block['rows'] !== []) : ?>
<div class="scroll">
<table>
<thead><tr><?php foreach ($block['headers'] as $title) : ?><th scope="col"><?= $e($title) ?></th><?php endforeach; ?></tr></thead>
<tbody>
<?php foreach ($block['rows'] as $row) : ?>
<tr><?php foreach ($row as $cell) : ?><td><?= $cell === null || trim($cell) === '' ? '–' : $e($cell) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php elseif ($block['kind'] === 'heading') : ?>
<h3><?= $e($block['text']) ?></h3>
<?php elseif ($block['kind'] === 'text') : ?>
<p<?= $block['italic'] ? ' class="muted"' : '' ?>><?= $rich($block['text']) ?><?= $block['origin'] === null ? '' : ' <small class="origin">' . $origin($block['origin']) . '</small>' ?></p>
<?php elseif ($block['kind'] === 'markdown') : ?>
<div class="note"><?= $e($block['body']) ?></div>
<?php else : ?>
<?php if ($block['intro'] !== null) : ?>
<p class="intro"><?= $e($block['intro']) ?></p>
<?php endif; ?>
<ul>
<?php foreach ($block['items'] as $item) : ?>
<li><?= $rich($item['text']) ?><?= $item['origin'] === null ? '' : ' <small class="origin">' . $origin($item['origin']) . '</small>' ?><?= $item['after'] === null ? '' : ' ' . $e($item['after']) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
</main>
</body>
</html>
