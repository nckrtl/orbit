<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Project;

final readonly class TiaBaselineSetup
{
    public function __construct(private TiaBaselineSource $source, private CommandDeadline $deadline) {}

    public function command(Project $project, float $timeout): string
    {
        return $this->deadline->withinForwardWork($timeout, function () use ($project): string {
            $program = file_get_contents(resource_path('instances/tia-baseline.py'));
            if (! is_string($program) || $program === '') {
                throw new ResourceOperationException('instance.tia_baseline_unavailable', 'The TIA baseline installer is unavailable.', 503);
            }
            $files = $this->source->fetch($project);
            $encoded = array_map(base64_encode(...), $files->files);
            $payload = json_encode($encoded, JSON_THROW_ON_ERROR);

            return 'python3 -c '.escapeshellarg($program)." <<'ORBIT_TIA_FILES'\n".$payload."\nORBIT_TIA_FILES\n";
        });
    }
}
