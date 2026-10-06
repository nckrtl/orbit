<?php

declare(strict_types=1);

use App\Infrastructure\ProjectDocuments\CleanupGate;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\ScheduleWorkCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArgvInput;

// Keep Laravel's real worker loop, child command construction, scheduler, and scheduled command execution.
// Only the clock and unrelated scheduled jobs are isolated for this disposable fixture.
define('ARTISAN_BINARY', __FILE__);
require __DIR__.'/../../../vendor/autoload.php';
$home = getenv('ORBIT_HOME');
if (! is_string($home)) {
    throw new RuntimeException('Missing disposable scheduler home.');
}
$clock = trim(file_get_contents($home.'/scheduler-clock'));
Carbon::setTestNow($clock);
Date::setTestNow($clock);
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$kernel = $app->make(Kernel::class);
// Mirror the real artisan entry point, including lifecycle events in APP_ENV=testing.
$kernel->rerouteSymfonyCommandEvents();
$kernel->bootstrap();
config(['database.connections.sqlite.database' => $home.'/database.sqlite', 'orbit.home' => $home,
    'orbit.document_cleanup_runtime' => $home.'/runtime']);
DB::purge('sqlite');
// Initializing Artisan runs Application::withSchedule's registration callback.
$kernel->all();
$schedule = app(Schedule::class);
$cleanup = array_values(array_filter($schedule->events(), static fn ($event): bool => str_contains($event->command ?? '', 'project-documents:cleanup:work')));
if (count($cleanup) !== 1 || $cleanup[0]->expression !== '*/5 * * * *') {
    throw new RuntimeException('The production cleanup schedule is missing or has changed.');
}
new ReflectionProperty(Schedule::class, 'events')->setValue($schedule, $cleanup);
if (($argv[1] ?? '') === 'schedule:work') {
    $kernel->registerCommand(new class($home) extends ScheduleWorkCommand
    {
        public function __construct(private readonly string $home)
        {
            parent::__construct();
        }

        protected function sleep(): void
        {
            usleep(10_000);
            $clock = trim(file_get_contents($this->home.'/scheduler-clock'));
            Carbon::setTestNow($clock);
            Date::setTestNow($clock);
        }
    });
    Event::listen(CommandStarting::class, static function (CommandStarting $event) use ($home): void {
        if ($event->command === 'schedule:work') {
            file_put_contents($home.'/scheduler-ready', json_encode(app(CleanupGate::class)->status()->toArray(), JSON_THROW_ON_ERROR));
        }
    });
}
$status = $app->handleCommand(new ArgvInput);
if (($argv[1] ?? '') === 'schedule:run') {
    $path = $home.'/tick-'.str_replace(':', '-', $clock);
    file_put_contents($path.'.next', json_encode([
        'exit_code' => $status, ...app(CleanupGate::class)->status()->toArray(),
    ], JSON_THROW_ON_ERROR));
    rename($path.'.next', $path);
}
exit($status);
