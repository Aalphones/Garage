<?php

declare(strict_types=1);

namespace Garage\Db;

use LogicException;
use PDO;
use Throwable;

/** Aus DISPATCH (ADR-005). */
final class Transaction
{
    /**
     * Läuft in der laufenden Transaktion mit, sonst in einer neuen. So bleibt eine Fach-Änderung,
     * die ein anderer Service in seiner Transaktion aufruft, atomar.
     *
     * @param callable(): mixed $work
     */
    public static function join(PDO $pdo, callable $work): mixed
    {
        if ($pdo->inTransaction()) {
            return $work();
        }

        return self::run($pdo, $work);
    }

    /**
     * Wie `run`, aber am Ende immer zurückgerollt: die Arbeit läuft vollständig (Sperren, Abgleich, Ergebnisse),
     * hinterlässt aber nichts. Eine laufende Transaktion würde das Zurückrollen auf mehr als die eigene Arbeit ausdehnen.
     *
     * @param callable(): mixed $work
     */
    public static function dryRun(PDO $pdo, callable $work): mixed
    {
        if ($pdo->inTransaction()) {
            throw new LogicException('Ein Trockenlauf kann nicht in einer laufenden Transaktion starten.');
        }

        $pdo->beginTransaction();

        try {
            return $work();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * Nur für DML — DDL committet in MySQL implizit (Migrator läuft deshalb ohne Transaktion).
     *
     * @param callable(): mixed $work
     */
    public static function run(PDO $pdo, callable $work): mixed
    {
        $pdo->beginTransaction();

        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }
}
