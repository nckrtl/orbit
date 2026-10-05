<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\ProjectDocuments\CleanupGate;
use App\Infrastructure\ProjectDocuments\DocumentsFilesystem;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

final class DocumentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('documents', fn (): AwsS3V3Adapter => app(DocumentsFilesystem::class)->current());
        Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
            if (in_array($event->command, ['schedule:run', 'schedule:work', 'queue:work', 'queue:listen'], true)) {
                app(CleanupGate::class)->invalidate();
            }
        });
    }
}
