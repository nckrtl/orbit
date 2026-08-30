<?php

declare(strict_types=1);

use App\E2E\Value\ProofPlan;
use Symfony\Component\Process\Process;

describe('Doctor proof plan', function (): void {
    it('declares a valid plan whose fixture subcommands exist on checkout roles only', function (): void {
        $root = dirname(__DIR__, 3).'/resources/proof';
        $fixture = '/home/orbit/orbit/apps/e2e/resources/proof/doctor-proof.sh';
        $plan = ProofPlan::fromFile("{$root}/nck-58-doctor.json");
        $script = file_get_contents("{$root}/doctor-proof.sh");

        expect($script)
            ->toBeString()
            ->toContain('set -euo pipefail')
            ->and(is_executable("{$root}/doctor-proof.sh"))
            ->toBeTrue()
            ->and(new Process(['bash', '-n', "{$root}/doctor-proof.sh"])->run())
            ->toBe(0)
            ->and($plan->postDeploymentActions)
            ->toBe([]);

        foreach ([...$plan->setup, ...$plan->acceptance] as $action) {
            if ($action['argv'][0] === $fixture) {
                expect($action['node'])
                    ->toBeIn(['gateway', 'app-dev'])
                    ->and($script)
                    ->toContain("\n  {$action['argv'][1]})");

                continue;
            }

            expect($action['node'])
                ->toBe('app-prod')
                ->and(array_slice($action['argv'], 0, 3))
                ->toBe(['sudo', 'bash', '-c'])
                ->and($action['argv'][3])
                ->toContain('set -eu');
        }
    });
});
