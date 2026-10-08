<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Projects\TiaBaselineFiles;
use App\Domain\Projects\TiaBaselineSource;
use App\Models\Project;
use ZipArchive;

final readonly class TiaBaselineTestSource implements TiaBaselineSource
{
    public function fetch(Project $project): TiaBaselineFiles
    {
        $path = tempnam(sys_get_temp_dir(), 'tia-setup-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('graph.json', '{"baselines":{"main":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","complete":true}}}');
        $zip->addFromString('js-module-graph.cache.json', '{}');
        $zip->close();
        try {
            return TiaBaselineFiles::fromArchive(file_get_contents($path), 'main', str_repeat('a', 40));
        } finally {
            unlink($path);
        }
    }
}
