<?php

declare(strict_types=1);

use App\Support\Tui\Sources\GatewayDatabaseUsersSource;
use Orbit\Sdk\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class);

describe(GatewayDatabaseUsersSource::class, function (): void {
    it('maps the recorded database user list response into Users pane rows', function (): void {
        $source = new GatewayDatabaseUsersSource(gateway_fixture_send('database-connections/database-user-list/default'));

        $rows = $source->forConnection('charlie-shop');

        expect($rows)->toBe([
            [
                'username' => 'app',
                'privileges' => 'ALL PRIVILEGES ON `app`.*',
                'created_by' => 'gateway',
            ],
        ]);
    });

    it('returns null when the Gateway request fails', function (): void {
        $source = new GatewayDatabaseUsersSource(fn (): never => throw new GatewayApiException('nope', 'gateway.request_failed'));

        expect($source->forConnection('charlie-shop'))->toBeNull();
    });
});
