<?php

/*
| Boots StarSystem the way a fresh upload does: no .env, so no APP_KEY and
| no database, and answers one request. Run by NoEnvironmentBootTest in its
| own process, because a test process has already loaded an environment.
|
| Usage: php boot-without-env.php <empty folder to read .env from> <uri>
*/

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->useEnvironmentPath($argv[1]);

$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request = Request::create($argv[2]));

echo json_encode([
    'status' => $response->getStatusCode(),
    'location' => $response->headers->get('Location'),
    'body' => (string) $response->getContent(),
    'encrypterResolved' => $app->resolved('encrypter'),
    'cookies' => array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies()),
]);

$kernel->terminate($request, $response);
