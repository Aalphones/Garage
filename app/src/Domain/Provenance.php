<?php

declare(strict_types=1);

namespace Garage\Domain;

/** Herkunft einer Angabe: Quelle, Adresse und Konfidenz. Jede Eingabe mit Herkunft läuft hier durch. */
final class Provenance
{
    public const SOURCES = ['beleg', 'nutzer', 'recherche', 'agent', 'werkstatt'];
    public const CONFIDENCE_RANK = ['unsicher' => 1, 'wahrscheinlich' => 2, 'bestaetigt' => 3];
    public const CONFIRMED = 'bestaetigt';

    /**
     * `$context` steht in den Fehlertexten, wo sonst der Feldname stünde (bei Fakten das Feld, bei Listen der Tool-Parameter).
     *
     * @param array<string, mixed> $input
     * @return array{source: string, source_url: string|null, confidence: string}
     */
    public static function validate(array $input, string $context): array
    {
        $source = $input['source'] ?? null;
        if (!is_string($source) || !in_array($source, self::SOURCES, true)) {
            throw new DomainError('invalid_value', sprintf('Feld „%s“: source muss eine von %s sein. Beispiel: "source": "beleg"', $context, implode(', ', self::SOURCES)), ['field' => $context]);
        }
        $confidence = $input['confidence'] ?? null;
        if (!is_string($confidence) || !isset(self::CONFIDENCE_RANK[$confidence])) {
            throw new DomainError('invalid_value', sprintf('Feld „%s“: confidence muss eine von %s sein. Beispiel: "confidence": "wahrscheinlich"', $context, implode(', ', array_keys(self::CONFIDENCE_RANK))), ['field' => $context]);
        }

        return [
            'source' => $source,
            'source_url' => Validate::url($context, $input['source_url'] ?? null),
            'confidence' => $confidence,
        ];
    }

    public static function isHigher(string $confidence, string $than): bool
    {
        return self::CONFIDENCE_RANK[$confidence] > self::CONFIDENCE_RANK[$than];
    }
}
