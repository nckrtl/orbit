<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Orbit\Sdk\Responses\Deployments\DeploymentStepResponse;

trait InstanceOutput
{
    private function writeInstanceDetails(AppInstanceResponse $instance): void
    {
        $fields = [
            'ID' => $instance->id,
            'App' => $instance->appId,
            'Node' => $instance->nodeId,
            'Status' => $instance->status,
            'Environment' => $instance->environment,
            'Source layout' => $instance->sourceLayout,
            'Checkout' => $instance->checkoutPath,
            'Vite port' => $instance->vitePort,
            'Root override' => $instance->root,
            'Effective root' => $instance->effectiveRoot,
            'Selected branch' => $instance->selectedBranch,
            'Branch override' => $instance->branchOverride,
            'Migration required' => $instance->migrationRequired ? 'yes' : 'no',
            'Starting commit' => $instance->startingCommit,
            'Git state' => $instance->detached ? 'detached' : $instance->selectedBranch,
            'Route domain' => $instance->domain,
            'URL' => $instance->url,
        ];

        if ($instance->productionUser !== null) {
            $fields['Production user'] = $instance->productionUser;
            $fields['Production home'] = $instance->productionHome;
        }

        if ($instance->removal !== null) {
            $removal = $instance->removal;
            $fields += [
                'Removal mode' => $removal->force ? 'forced' : 'normal',
                'Removal progress' => "{$removal->completed}/{$removal->total} completed; {$removal->remaining} remaining",
                'Removal step' => $removal->currentStep,
                'Removal failed step' => $removal->failedStep,
                'Removal error code' => $removal->errorCode,
            ];
        }

        $fields['Request ID'] = $instance->requestId;
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("App instance: {$instance->name}", $fields));
    }

    /** @param list<DeploymentStepResponse> $steps */
    private function writeDeploySteps(array $steps): void
    {
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Name', 'Phase', 'Command', 'Timeout (seconds)'],
            array_map(static fn (DeploymentStepResponse $step): array => [
                $step->name, $step->phase, $step->command, $step->timeoutSeconds,
            ], $steps),
            'No deploy steps found.',
        ));
    }

    private function writeDeployStep(DeploymentStepResponse $step): void
    {
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Deploy step: {$step->name}", [
            'Phase' => $step->phase,
            'Command' => $step->command,
            'Timeout (seconds)' => $step->timeoutSeconds,
            'Request ID' => $step->requestId,
        ]));
    }
}
