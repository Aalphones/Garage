<?php

declare(strict_types=1);

use Garage\Api\AuthController;
use Garage\Api\CostController;
use Garage\Api\DeployController;
use Garage\Api\DocumentController;
use Garage\Api\DueController;
use Garage\Api\EventController;
use Garage\Api\InboxController;
use Garage\Api\FileController;
use Garage\Api\UploadController;
use Garage\Api\HealthController;
use Garage\Api\InvitationController;
use Garage\Api\MeController;
use Garage\Api\OpenItemController;
use Garage\Api\PrintController;
use Garage\Api\RecordController;
use Garage\Api\TokenController;
use Garage\Api\ValueController;
use Garage\Api\VehicleController;
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

$router->add('GET', '/garage/api/vehicles', static fn () => (new VehicleController($config))->list($request));
$router->add('GET', '/garage/api/vehicles/{id}', static fn (array $parameters) => (new VehicleController($config))->show($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/vehicles/{id}/due', static fn (array $parameters) => (new VehicleController($config))->due($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/vehicles/{id}/events', static fn (array $parameters) => (new EventController($config))->listForVehicle($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/vehicles/{id}/odometer', static fn (array $parameters) => (new VehicleController($config))->odometer($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/vehicles/{id}/measurements', static fn (array $parameters) => (new VehicleController($config))->measurements($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/vehicles/{id}/tires', static fn (array $parameters) => (new VehicleController($config))->tires($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/vehicles/{id}/parts', static fn (array $parameters) => (new VehicleController($config))->parts($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/vehicles/{id}/notes', static fn (array $parameters) => (new VehicleController($config))->notes($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/vehicles/{id}/damages', static fn (array $parameters) => (new VehicleController($config))->damages($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/vehicles/{id}/dossier', static fn (array $parameters) => (new VehicleController($config))->dossier($request, (int) $parameters['id']));
$router->add('PATCH', '/garage/api/vehicles/{id}', static fn (array $parameters) => (new VehicleController($config))->updateStatus($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/vehicles/{id}/delete', static fn (array $parameters) => (new VehicleController($config))->delete($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/vehicles/{id}/restore', static fn (array $parameters) => (new VehicleController($config))->restore($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/vehicles/{id}/print-link', static fn (array $parameters) => (new VehicleController($config))->printLink($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/vehicles/{id}/odometer', static fn (array $parameters) => (new VehicleController($config))->addOdometer($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/vehicles/{id}/fuel', static fn (array $parameters) => (new CostController())->fuel($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/vehicles/{id}/fuel', static fn (array $parameters) => (new CostController())->addFuel($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/vehicles/{id}/expenses', static fn (array $parameters) => (new CostController())->expenses($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/vehicles/{id}/expenses', static fn (array $parameters) => (new CostController())->addExpense($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/vehicles/{id}/value', static fn (array $parameters) => (new ValueController())->summary($request, (int) $parameters['id']));
$router->add('PATCH', '/garage/api/vehicles/{id}/facts', static fn (array $parameters) => (new VehicleController($config))->correctFact($request, (int) $parameters['id']));
$router->add('PUT', '/garage/api/vehicles/{id}/notes', static fn (array $parameters) => (new VehicleController($config))->upsertNote($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/vehicles/{id}/cover', static fn (array $parameters) => (new VehicleController($config))->setCover($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/records/{type}/{id}/delete', static fn (array $parameters) => (new RecordController())->delete($request, (string) $parameters['type'], (int) $parameters['id']));
$router->add('POST', '/garage/api/records/{type}/{id}/restore', static fn (array $parameters) => (new RecordController())->restore($request, (string) $parameters['type'], (int) $parameters['id']));
$router->add('GET', '/garage/api/events/{id}', static fn (array $parameters) => (new EventController($config))->show($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/due', static fn () => (new DueController())->list($request));
$router->add('GET', '/garage/api/tokens',static fn () => (new TokenController())->list($request));
$router->add('POST', '/garage/api/tokens', static fn () => (new TokenController())->create($request));
$router->add('DELETE', '/garage/api/tokens/{id}', static fn (array $parameters) => (new TokenController())->revoke($request, (int) $parameters['id']));

$router->add('GET', '/garage/api/open-items', static fn () => (new OpenItemController())->list($request));
$router->add('POST', '/garage/api/open-items/{id}/resolve', static fn (array $parameters) => (new OpenItemController())->resolve($request, (int) $parameters['id']));

$router->add('GET', '/garage/api/documents', static fn () => (new DocumentController($config))->list($request));
$router->add('POST', '/garage/api/documents', static fn () => (new DocumentController($config))->create($request));
$router->add('GET', '/garage/api/documents/{id}', static fn (array $parameters) => (new DocumentController($config))->show($request, (int) $parameters['id']));
$router->add('PATCH', '/garage/api/documents/{id}', static fn (array $parameters) => (new DocumentController($config))->update($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/documents/{id}/file', static fn (array $parameters) => (new DocumentController($config))->uploadFile($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/documents/{id}/discard', static fn (array $parameters) => (new DocumentController($config))->discard($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/documents/{id}/restore', static fn (array $parameters) => (new DocumentController($config))->restore($request, (int) $parameters['id']));
$router->add('GET', '/garage/api/inbox', static fn () => (new InboxController($config))->list($request));
$router->add('POST', '/garage/api/inbox', static fn () => (new InboxController($config))->create($request));
$router->add('PATCH', '/garage/api/inbox/{id}', static fn (array $parameters) => (new InboxController($config))->updateNote($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/inbox/{id}/discard', static fn (array $parameters) => (new InboxController($config))->discard($request, (int) $parameters['id']));
$router->add('POST', '/garage/api/inbox/{id}/restore', static fn (array $parameters) => (new InboxController($config))->restore($request, (int) $parameters['id']));

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
$router->add('OPTIONS', '/garage/upload/{slug}', static fn () => (new UploadController($config))->options());
$router->add('GET', '/garage/files/{slug}', static fn (array $parameters) => (new FileController($config))->show($request, (string) $parameters['slug']));
$router->add('HEAD', '/garage/files/{slug}', static fn (array $parameters) => (new FileController($config))->show($request, (string) $parameters['slug']));

// Ohne Anmeldung: die Signatur im Pfad ist die Berechtigung (ADR-018).
$router->add('GET', '/garage/akte/{slug}', static fn (array $parameters) => (new PrintController())->show($request, (string) $parameters['slug']));

$router->add('POST', '/garage/mcp', static fn () => (new McpController($config))->handle($request));

$router->dispatch($request);
