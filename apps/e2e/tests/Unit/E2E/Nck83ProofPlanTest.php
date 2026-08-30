<?php

declare(strict_types=1);

use App\E2E\Value\ProofFixtures;
use App\E2E\Value\ProofPlan;
use Symfony\Component\Process\Process;

describe('NCK-83 proof plan', function (): void {
    it('proves re-projected product state on an untouched topology without setup actions', function (): void {
        $root = dirname(__DIR__, 3).'/resources/proof/NCK-83';
        $plan = ProofPlan::fromFile("{$root}/plan.json");
        $fixture = ProofFixtures::guestPath('reproject-proof.sh');
        $script = file_get_contents("{$root}/reproject-proof.sh");

        expect(is_executable("{$root}/reproject-proof.sh"))
            ->toBeTrue()
            ->and(new Process(['bash', '-n', "{$root}/reproject-proof.sh"])->run())
            ->toBe(0)
            ->and($script)
            ->toContain("\n  doctor-projections)", "\n  pool-markers)", 'opcache.validate_timestamps')
            ->and($plan->setup)
            ->toBe([])
            ->and($plan->postDeploymentActions)
            ->toBe([])
            ->and(array_map(static fn (array $action): array => [
                $action['node'],
                array_slice($action['argv'], 0, 2),
            ], $plan->acceptance))
            ->toBe([
                ['app-dev', [$fixture, 'doctor-projections']],
                ['app-dev', [$fixture, 'pool-markers']],
                ['app-prod', ['sudo', 'bash']],
            ]);

        foreach (scandir($root) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                expect(ProofFixtures::isFixtureName($name))->toBeTrue();
            }
        }
    });
});
