<?php

declare(strict_types=1);

use Garage\Api\AuthController;
use Garage\Api\DeployController;
use Garage\Api\FileController;
use Garage\Api\UploadController;
use Garage\Api\HealthController;
use Garage\Api\InvitationController;
use Garage\Api\MeController;
use Garage\Api\OpenItemController;
use Garage\Api\TokenController;
use Garage\Api\OAuthController;
use Garage\Http\Request;
use Garage\Http\Router;
use Garage\Mcp\McpController;
use Garage\OAuth\AuthorizeController;
use Garage\OAuth\DiscoveryController;
use Garage\OAuth\TokenController as OAuthTokenController;

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

$router->add('GET', '/garage/api/open-items', static fn () => (new OpenItemController())->list($request));
$router->add('POST', '/garage/api/open-items/{id}/resolve', static fn (array $parameters) => (new OpenItemController())->resolve($request, (int) $parameters['id']));

// Discovery liegt mit Pfad-Suffix an der Domain-Wurzel; DISPATCHs Wurzel-.htaccess leitet sie hierher (ADR-001).
$router->add('GET', '/.well-known/oauth-protected-resource/garage/mcp', static fn () => (new DiscoveryController($config))->protectedResource());
$router->add('GET', '/.well-known/oauth-authorization-server/garage', static fn () => (new DiscoveryController($config))->authorizationServer());
$router->add('GET', '/garage/oauth/authorize', static fn () => (new AuthorizeController($config))->handle($request));
$router->add('POST', '/garage/oauth/token', static fn () => (new OAuthTokenController($config))->token($request));
$router->add('POST', '/garage/oauth/revoke', static fn () => (new OAuthTokenController($config))->revoke($request));

$router->add('GET', '/garage/api/oauth/requests/{slug}', static fn (array $parameters) => (new OAuthController($config))->request($request, (string) $parameters['slug']));
$router->add('POST', '/garage/api/oauth/consent', static fn () => (new OAuthController($config))->consent($request));
$router->add('GET', '/garage/api/oauth/grants', static fn () => (new OAuthController($config))->grants($request));
$router->add('DELETE', '/garage/api/oauth/grants/{slug}', static fn (array $parameters) => (new OAuthController($config))->disconnect($request, (string) $parameters['slug']));

// Beide ohne Anmeldung: Ticket bzw. Signatur im Pfad ist die Berechtigung (ADR-012).
$router->add('POST', '/garage/upload/{slug}', static fn (array $parameters) => (new UploadController($config))->upload($request, (string) $parameters['slug']));
$router->add('GET', '/garage/files/{slug}', static fn (array $parameters) => (new FileController($config))->show($request, (string) $parameters['slug']));
$router->add('HEAD', '/garage/files/{slug}', static fn (array $parameters) => (new FileController($config))->show($request, (string) $parameters['slug']));

$router->add('POST', '/garage/mcp', static fn () => (new McpController($config))->handle($request));

$router->dispatch($request);
