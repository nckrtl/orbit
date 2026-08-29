<?php

declare(strict_types=1);

describe('console bootstrap', function () {
    it('boots the artisan entry point and lists E2E commands', function () {
        $artisan = dirname(__DIR__, levels: 3).'/artisan';
        $command = sprintf(
            'cd %s && APP_ENV=local APP_DEBUG=true %s %s list --raw 2>&1',
            escapeshellarg(sys_get_temp_dir()),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($artisan),
        );

        exec($command, $output, $exitCode);

        expect($exitCode)
            ->toBe(0)
            ->and(implode(PHP_EOL, $output))
            ->toContain('standby:fingerprint', 'boost:update');
    });
});
