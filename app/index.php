<?php

declare(strict_types=1);

use Garage\Api\DeployController;
use Garage\Api\HealthController;
use Garage\Http\Request;
use Garage\Http\Router;

$config = require __DIR__ . '/bootstrap.php';

$request = Request::fromGlobals();

$router = new Router();
$router->add('GET', '/garage/api/health', static fn () => (new HealthController())->show());
$router->add('POST', '/garage/api/deploy', static fn () => (new DeployController($config))->handle($request));

$router->dispatch($request);
