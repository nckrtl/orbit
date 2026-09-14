<?php

declare(strict_types=1);

use App\Domain\Hibernation\RuntimeHibernation;

it('names per-AppInstance marker and access-log paths', function (): void {
    expect(RuntimeHibernation::key(12))
        ->toBe('app-instance-12')
        ->and(RuntimeHibernation::awakePath('app-instance-12'))
        ->toBe('/dev/shm/orbit/hibernation/app-instance-12.awake')
        ->and(RuntimeHibernation::accessLogPath('app-instance-12'))
        ->toBe('/data/caddy/orbit/hibernation/app-instance-12.log')
        ->and(RuntimeHibernation::coldPath('app-instance-12'))
        ->toBe('/data/caddy/orbit/hibernation/app-instance-12.cold')
        ->and(RuntimeHibernation::DefaultIdleSeconds)
        ->toBe(3_600)
        ->and(RuntimeHibernation::DefaultDependencyIdleSeconds)
        ->toBe(604_800)
        ->and(RuntimeHibernation::parseAppInstanceId('app-instance-12'))
        ->toBe(12);
});

it('rejects unsafe hibernation keys', function (): void {
    expect(fn () => RuntimeHibernation::key(0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => RuntimeHibernation::awakePath('workspace-1'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => RuntimeHibernation::awakePath('app-instance-12/../etc'))
        ->toThrow(InvalidArgumentException::class);
});
