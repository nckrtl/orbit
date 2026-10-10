<?php

declare(strict_types=1);

use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Tests\Support\HostPathGuardedProcessRunner;

it('refuses a privileged command that names a managed host path before it runs', function (string $input): void {
    $marker = sys_get_temp_dir().'/host-path-guard-'.bin2hex(random_bytes(4));

    expect(fn () => app(ProcessRunner::class)->run(new ProcessInvocation(
        ['sudo', '-n', 'bash', '-seu'],
        input: "touch '{$marker}'\n{$input}\n",
    )))->toThrow(LogicException::class, 'privileged command against the host path');

    expect(file_exists($marker))->toBeFalse();
})->with([
    'systemd unit' => ['unit_directory=/etc/systemd/system'],
    'Orbit state' => ['catalog="/var/lib/orbit/private-dns/catalog.json"'],
    'dnsmasq fragment' => ['install -m 0644 x /etc/dnsmasq.d/orbit-records.conf'],
]);

it('allows the same paths under a temporary root and unprivileged reads', function (): void {
    HostPathGuardedProcessRunner::assertSafe(['sudo', 'bash', '-seu'], 'unit_directory=/tmp/orbit-x/etc/systemd/system');
    HostPathGuardedProcessRunner::assertSafe(['bash', '-seu'], posix_geteuid() === 0 ? 'true' : 'cat /etc/systemd/system/x');

    expect(true)->toBeTrue();
});
