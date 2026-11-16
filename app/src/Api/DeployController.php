<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\DeployToken;
use Garage\Db\Connection;
use Garage\Db\Migrator;
use Garage\Http\Request;
use Garage\Http\Response;
use Garage\Util\Env;
use Garage\Util\Log;
use PDO;
use Throwable;

/** `POST /garage/api/deploy`: von `deploy.cmd` nach dem Hochladen aufgerufen; ohne SSH der einzige Weg zu den Migrationen (ADR-003). */
final class DeployController
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function handle(Request $request): never
    {
        if (!DeployToken::matches($request, Env::get('GARAGE_DEPLOY_TOKEN'))) {
            Response::error('unauthorized', 'Deploy-Token fehlt oder ist falsch.', 401);
        }

        $pdo = $this->step('datenbank', static fn (): PDO => Connection::open());

        $migrations = $this->step('migrationen', fn (): array => (new Migrator($pdo, (string) $this->config['app_dir'] . '/migrations'))->run());

        Response::json(['migrations' => $migrations]);
    }

    /**
     * @template T
     * @param callable(): T $action
     * @return T
     */
    private function step(string $name, callable $action): mixed
    {
        try {
            return $action();
        } catch (Throwable $exception) {
            // Der Ausnahme-Text kann Host oder Benutzer enthalten: nur ins Log, nie in die Antwort.
            Log::error('Deploy-Schritt fehlgeschlagen', [
                'step' => $name,
                'type' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            Response::error('deploy_failed', 'Schritt ' . $name . ' fehlgeschlagen, Details im Server-Log.', 500);
        }
    }
}
