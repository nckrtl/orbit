<?php

declare(strict_types=1);

use App\Console\Commands\Standby\FingerprintCommand;
use App\Console\Commands\Standby\RefreshCommand;
use App\Console\Commands\Standby\RestoreCommand;
use App\Console\Commands\Standby\StatusCommand;

describe('standby commands', function () {
    it('registers one thin command for each wrapper action', function () {
        expect([
            new StatusCommand()->getName(),
            new FingerprintCommand()->getName(),
            new RefreshCommand()->getName(),
            new RestoreCommand()->getName(),
        ])->toBe(['standby:status', 'standby:fingerprint', 'standby:refresh', 'standby:restore']);
    });

    it('rejects refresh without an exact main SHA', function () {
        $this
            ->artisan('standby:refresh', ['--json' => true])
            ->expectsOutputToContain('exact main SHA')
            ->assertFailed();
    });
});
