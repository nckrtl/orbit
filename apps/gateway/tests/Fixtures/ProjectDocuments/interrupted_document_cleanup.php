<?php

declare(strict_types=1);

use App\Actions\ProjectDocuments\WorkDocumentCleanupAction;
use Aws\Handler\Guzzle\GuzzleHandler;
use GuzzleHttp\Client;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$app->make(Kernel::class)->bootstrap();
$home = $argv[1];
$mode = $argv[2];
config(['database.connections.sqlite.database' => $home.'/database.sqlite',
    'orbit.home' => $home, 'orbit.document_cleanup_runtime' => $home.'/runtime']);
DB::purge('sqlite');
if ($mode === 'before-handoff') {
    $pdo = DB::getPdo();
    $pdo->sqliteCreateFunction('crash_cleanup', static function (): void {
        posix_kill(getmypid(), 9);
    });
    $pdo->exec('CREATE TEMP TRIGGER interrupt_handoff BEFORE DELETE ON project_document_uploads BEGIN SELECT crash_cleanup(); END');
}
$http = new GuzzleHandler(new Client);
config(['filesystems.disks.documents.http_handler' => static function (RequestInterface $request, array $options) use ($http, $home, $mode) {
    if ($mode === 'before-delete') {
        posix_kill(getmypid(), 9);
    }
    if ($mode === 'hold') {
        file_put_contents($home.'/authorized', 'ready');
        $deadline = microtime(true) + 10;
        while (! is_file($home.'/release')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Fixture deadline exceeded.');
            }
            usleep(10_000);
        }
    }

    return $http($request, $options)->then(static function (ResponseInterface $response) use ($mode): ResponseInterface {
        if ($mode === 'after-delete') {
            posix_kill(getmypid(), 9);
        }

        return $response;
    });
}]);
echo json_encode(app(WorkDocumentCleanupAction::class)->handle(), JSON_THROW_ON_ERROR);
