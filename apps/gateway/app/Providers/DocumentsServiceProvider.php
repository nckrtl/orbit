<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\ProjectDocuments\DocumentsFilesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

final class DocumentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('documents', fn (): AwsS3V3Adapter => app(DocumentsFilesystem::class)->current());
    }
}
