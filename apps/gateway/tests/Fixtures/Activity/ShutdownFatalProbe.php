<?php

declare(strict_types=1);

use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Models\Activity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/*
 * Starts a `running` Activity the way RecordCommandActivity does, then dies of a fatal error before the
 * request records an outcome. ORBIT_ACTIVITY_PROBE_MODE=disarmed records the outcome first.
 */

require dirname(__DIR__, 2).'/bootstrap.php';

$app = require Application::inferBasePath().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

Artisan::call('migrate', ['--force' => true]);

$activity = Activity::query()->create([
    'log_name' => 'commands',
    'description' => 'instance:register',
    'event' => 'command',
    'request_id' => (string) Str::uuid(),
    'command' => 'instance:register',
    'status' => 'running',
]);
$shutdown = ActivityShutdownFinalizer::arm($activity);

if (getenv('ORBIT_ACTIVITY_PROBE_MODE') === 'disarmed') {
    $activity->update(['status' => 'succeeded']);
    $shutdown->disarm();
}

ini_set('memory_limit', '64M');
$hog = [];

while (true) {
    $hog[] = str_repeat('x', 1_048_576);
}
