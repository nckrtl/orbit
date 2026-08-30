<?php

declare(strict_types=1);

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Models\Setting;

describe(SettingRepository::class, function (): void {
    it('stores plain and encrypted scoped settings', function (): void {
        $repository = app(SettingRepository::class);
        $scope = new SettingScope(SettingScopeType::Gateway);

        $repository->put($scope, 'example.plain', 'plain');
        $repository->put(
            $scope,
            'ca.private_key',
            'private',
            protection: SettingValueProtection::Secret,
        );

        expect($repository->get($scope, 'example.plain'))
            ->toBe('plain')
            ->and($repository->get($scope, 'ca.private_key'))
            ->toBe('private')
            ->and(Setting::query()->where('key', 'ca.private_key')->value('value'))
            ->not->toBe('private');
    });

    it('deletes one typed scoped setting', function (): void {
        $repository = app(SettingRepository::class);
        $scope = new SettingScope(SettingScopeType::Node, 42);
        $repository->put($scope, 'metrics.pending_password', 'secret');

        expect($repository->delete($scope, 'metrics.pending_password'))
            ->toBeTrue()
            ->and($repository->get($scope, 'metrics.pending_password'))
            ->toBeNull()
            ->and($repository->delete($scope, 'metrics.pending_password'))
            ->toBeFalse();
    });
});
