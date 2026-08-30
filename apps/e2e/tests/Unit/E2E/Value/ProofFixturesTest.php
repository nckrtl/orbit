<?php

declare(strict_types=1);

use App\E2E\Value\ProofFixtures;
use App\E2E\Value\TopologyProfile;

describe('ProofFixtures', function (): void {
    $files = [
        'check.sh' => ['mode' => '755', 'sha256' => str_repeat('1', 64)],
        'plan.json' => ['mode' => '644', 'sha256' => str_repeat('2', 64)],
    ];
    $digest = ProofFixtures::digestOf($files);

    it('hashes the inventory text every role prints and round-trips', function () use ($files, $digest): void {
        $fixtures = new ProofFixtures($files, $digest, array_fill_keys(TopologyProfile::ROLES, $digest));

        expect(ProofFixtures::inventoryText($files))
            ->toBe("check.sh\t755\t".str_repeat('1', 64)."\nplan.json\t644\t".str_repeat('2', 64)."\n")
            ->and($digest)
            ->toBe(hash('sha256', ProofFixtures::inventoryText($files)))
            ->and(ProofFixtures::fromArray($fixtures->toArray())->toArray())
            ->toBe($fixtures->toArray())
            ->and(ProofFixtures::hostDirectory('NCK-82'))
            ->toBe('apps/e2e/resources/proof/NCK-82')
            ->and(ProofFixtures::guestPath('check.sh'))
            ->toBe('/var/lib/orbit-e2e/proof/check.sh');
    });

    it('rejects an inventory that a role did not observe, an unsorted or unsafe name, or a bad mode', function (
        Closure $build,
    ): void {
        expect($build)->toThrow(InvalidArgumentException::class);
    })->with([
        'role digest differs' => fn () => new ProofFixtures(
            ['check.sh' => ['mode' => '755', 'sha256' => str_repeat('1', 64)]],
            ProofFixtures::digestOf(['check.sh' => ['mode' => '755', 'sha256' => str_repeat('1', 64)]]),
            ['gateway' => str_repeat('0', 64), 'app-dev' => str_repeat('0', 64), 'app-prod' => str_repeat('0', 64)],
        ),
        'missing role' => fn () => new ProofFixtures([], hash('sha256', ''), ['gateway' => hash('sha256', '')]),
        'unsorted' => fn () => new ProofFixtures(
            [
                'plan.json' => ['mode' => '644', 'sha256' => str_repeat('2', 64)],
                'check.sh' => ['mode' => '755', 'sha256' => str_repeat('1', 64)],
            ],
            str_repeat('3', 64),
            array_fill_keys(TopologyProfile::ROLES, str_repeat('3', 64)),
        ),
        'unsafe name' => fn () => new ProofFixtures(
            ['../etc' => ['mode' => '644', 'sha256' => str_repeat('1', 64)]],
            str_repeat('3', 64),
            array_fill_keys(TopologyProfile::ROLES, str_repeat('3', 64)),
        ),
        'bad mode' => fn () => new ProofFixtures(
            ['check.sh' => ['mode' => '600', 'sha256' => str_repeat('1', 64)]],
            str_repeat('3', 64),
            array_fill_keys(TopologyProfile::ROLES, str_repeat('3', 64)),
        ),
        'guest path' => fn () => ProofFixtures::guestPath('nested/check.sh'),
    ]);
});
