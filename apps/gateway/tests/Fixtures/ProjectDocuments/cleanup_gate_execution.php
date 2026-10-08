<?php

declare(strict_types=1);

use App\Infrastructure\ProjectDocuments\CleanupGate;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['orbit.document_cleanup_runtime' => $argv[1]]);
$gate = $app->make(CleanupGate::class);
if (($argv[2] ?? '') === 'pause') {
    echo "pause-starting\n";
    $gate->invalidate();
    exit(0);
}
$executed = $gate->executeWithPermit(function () use ($argv): void {
    echo "provider-started\n";
    $deadline = microtime(true) + 10;
    while (! file_exists($argv[1].'/finish-provider')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Fixture provider deadline exceeded.');
        }
        usleep(10000);
    }
    echo "provider-finished\n";
});
exit($executed ? 0 : 1);
