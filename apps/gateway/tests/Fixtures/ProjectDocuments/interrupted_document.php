<?php

declare(strict_types=1);

use App\Actions\ProjectDocuments\WriteDocumentAction;
use App\Data\ProjectDocuments\DocumentBody;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$database = $argv[1];
$projectId = (int) $argv[2];
foreach (['DB_DATABASE' => $database, 'DB_URL' => '', 'APP_ENV' => 'testing', 'CACHE_STORE' => 'array'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$app->make(Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => $database]);
DB::purge('sqlite');
config(['filesystems.disks.documents.handler' => static function (CommandInterface $command) use ($database): PromiseInterface {
    if ($command->getName() === 'PutObject') {
        $independent = new PDO('sqlite:'.$database);
        $intent = $independent->query('SELECT * FROM project_document_uploads')->fetch(PDO::FETCH_ASSOC);
        $object = ['key' => $command['Key'], 'transaction_level' => DB::transactionLevel(),
            'intent_state_before_put' => $intent['state'], 'tracked_key_before_put' => $intent['storage_key'],
            'body' => (string) $command['Body']];
        file_put_contents(dirname($database).'/remote-object.json', json_encode($object, JSON_THROW_ON_ERROR));
        chmod(dirname($database).'/remote-object.json', 0600);
        posix_kill(getmypid(), 9);
    }

    return Create::promiseFor(new Result);
}]);
app(WriteDocumentAction::class)->create($projectId, 'interrupted', null, DocumentBody::text('interrupted bytes'), null);
