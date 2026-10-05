<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\ProjectDocuments\CleanupGate;
use App\Infrastructure\ProjectDocuments\CleanupSchedulerSession;
use App\Infrastructure\ProjectDocuments\DocumentsFilesystem;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CleanupSchedulerSession::class);
    }

    public function boot(): void
    {
        Storage::extend('documents', fn (): AwsS3V3Adapter => app(DocumentsFilesystem::class)->current());
        Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
            if ($event->command === 'schedule:run' && app(CleanupSchedulerSession::class)->isRecurringTick()) {
                return;
            }
            if (in_array($event->command, ['schedule:run', 'schedule:work', 'queue:work', 'queue:listen'], true)) {
                app(CleanupGate::class)->invalidate();
            }
            if ($event->command === 'schedule:work') {
                app(CleanupSchedulerSession::class)->start();
            }
        });
        Event::listen(CommandFinished::class, static function (CommandFinished $event): void {
            if ($event->command === 'schedule:work') {
                app(CleanupSchedulerSession::class)->close();
            }
        });
    }
}
