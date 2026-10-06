<?php

declare(strict_types=1);

namespace App\Domain\Instances\Apps;

final readonly class AppProjectionPlan
{
    /**
     * Configuration and fingerprints only. Environment bytes belong in protected storage.
     *
     * @param  array<string, mixed>  $beforeApps
     * @param  array<string, mixed>  $candidateApps
     * @param  array<string, mixed>  $beforeProfiles
     * @param  array<string, mixed>  $candidateProfiles
     * @param  array<string, mixed>  $placement
     * @param  array<string, mixed>  $resources
     */
    public function __construct(
        public int $instanceId,
        public int $nodeId,
        public array $beforeApps,
        public array $candidateApps,
        public array $beforeProfiles,
        public array $candidateProfiles,
        public array $placement,
        public ?string $selectedRelease,
        public array $resources,
    ) {}

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return [
            'before_apps' => $this->beforeApps,
            'candidate_apps' => $this->candidateApps,
            'before_profiles' => $this->beforeProfiles,
            'candidate_profiles' => $this->candidateProfiles,
            'placement' => $this->placement,
            'selected_release' => $this->selectedRelease,
            'resources' => $this->resources,
        ];
    }
}
