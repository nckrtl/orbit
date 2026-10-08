<?php

declare(strict_types=1);

use App\Infrastructure\ProjectDocuments\VerifyDocumentStorage;
use App\Models\ProjectDocumentStorage;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$home = $argv[1];
if (! is_dir($home)) {
    mkdir($home, 0700, true);
}
foreach (['DB_DATABASE' => ':memory:', 'DB_URL' => '', 'ORBIT_HOME' => $home, 'APP_ENV' => 'testing', 'CACHE_STORE' => 'array'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$app->make(Kernel::class)->bootstrap();
config(['orbit.home' => $home]);
config(['filesystems.disks.documents.handler' => static function (CommandInterface $command) use ($home): PromiseInterface {
    if ($command->getName() === 'PutObject') {
        file_put_contents($home.'/remote-object.json', json_encode(['key' => $command['Key'], 'configuration_transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR));
        chmod($home.'/remote-object.json', 0600);
        posix_kill(getmypid(), 9);
    }

    return Create::promiseFor(new Result);
}]);
DB::transaction(static fn () => app(VerifyDocumentStorage::class)->handle(new ProjectDocumentStorage([
    'endpoint' => 'https://old-store.example.test', 'region' => 'europe-2', 'bucket' => 'fixture-bucket',
    'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret',
])));
