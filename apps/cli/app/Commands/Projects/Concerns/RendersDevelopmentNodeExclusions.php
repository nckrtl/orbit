<?php

declare(strict_types=1);

namespace App\Commands\Projects\Concerns;

use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionResponse;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionsResponse;

trait RendersDevelopmentNodeExclusions
{
    protected function renderExclusion(DevelopmentNodeExclusionResponse $exclusion, string $verb): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($exclusion->toArray());

            return self::SUCCESS;
        }

        $message = match ($verb) {
            'add' => $exclusion->alreadyExists
                ? "Project [{$exclusion->projectSlug}] (#{$exclusion->projectId}) already excludes node [{$exclusion->nodeName}] (#{$exclusion->nodeId})."
                : "Excluded node [{$exclusion->nodeName}] (#{$exclusion->nodeId}) for project [{$exclusion->projectSlug}] (#{$exclusion->projectId}).",
            'remove' => "Removed the exclusion of node [{$exclusion->nodeName}] (#{$exclusion->nodeId}) for project [{$exclusion->projectSlug}] (#{$exclusion->projectId}).",
            default => "Node [{$exclusion->nodeName}] (#{$exclusion->nodeId}) is excluded for project [{$exclusion->projectSlug}] (#{$exclusion->projectId}).",
        };

        $this->writeHumanMessage($message);

        if ($exclusion->developmentInstanceCount > 0) {
            $this->writeHumanMessage("{$exclusion->developmentInstanceCount} development Instances of this Project are already on that Node.");
        }

        $this->writeHumanMessage("Request ID: {$exclusion->requestId}");

        return self::SUCCESS;
    }

    protected function renderExclusionList(DevelopmentNodeExclusionsResponse $exclusions): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($exclusions->toArray());

            return self::SUCCESS;
        }

        if ($exclusions->exclusions === []) {
            $this->writeHumanMessage('No development node exclusions.');
            $this->writeHumanMessage("Request ID: {$exclusions->requestId}");

            return self::SUCCESS;
        }

        foreach ($exclusions->exclusions as $exclusion) {
            $this->writeHumanMessage("{$exclusion->projectSlug} (#{$exclusion->projectId}) excludes {$exclusion->nodeName} (#{$exclusion->nodeId}). {$exclusion->developmentInstanceCount} development Instances are already there.");
        }

        $this->writeHumanMessage("Request ID: {$exclusions->requestId}");

        return self::SUCCESS;
    }
}
