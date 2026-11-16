<?php

declare(strict_types=1);

use Garage\Api\AuthController;
use Garage\Api\DeployController;
use Garage\Api\HealthController;
use Garage\Api\InvitationController;
use Garage\Api\MeController;
use Garage\Api\TokenController;
use Garage\Http\Request;
use Garage\Http\Router;

$config = require __DIR__ . '/bootstrap.php';

$request = Request::fromGlobals();

$router = new Router();
$router->add('GET', '/garage/api/health', static fn () => (new HealthController())->show());
$router->add('POST', '/garage/api/deploy', static fn () => (new DeployController($config))->handle($request));

$router->add('POST', '/garage/api/admin/invitations', static fn () => (new InvitationController())->create($request));
$router->add('POST', '/garage/api/invitations/preview', static fn () => (new InvitationController())->preview($request));
$router->add('POST', '/garage/api/invitations/accept', static fn () => (new InvitationController())->accept($request));

$router->add('POST', '/garage/api/auth/login', static fn () => (new AuthController())->login($request));
$router->add('POST', '/garage/api/auth/refresh', static fn () => (new AuthController())->refresh($request));
$router->add('POST', '/garage/api/auth/logout', static fn () => (new AuthController())->logout($request));
$router->add('POST', '/garage/api/auth/logout-all', static fn () => (new AuthController())->logoutAll($request));
$router->add('GET', '/garage/api/me', static fn () => (new MeController())->show($request));

$router->add('GET', '/garage/api/tokens', static fn () => (new TokenController())->list($request));
$router->add('POST', '/garage/api/tokens', static fn () => (new TokenController())->create($request));
$router->add('DELETE', '/garage/api/tokens/{id}', static fn (array $parameters) => (new TokenController())->revoke($request, (int) $parameters['id']));

$router->dispatch($request);
