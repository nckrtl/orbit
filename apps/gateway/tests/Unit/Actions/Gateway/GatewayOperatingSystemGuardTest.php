<?php

declare(strict_types=1);

use App\Actions\Gateway\GatewayOperatingSystemGuard;
use App\Domain\Nodes\NodeProvisioningException;
use Illuminate\Support\Str;

it('accepts the supported local gateway operating system', function (): void {
    $path = gateway_os_release_fixture("ID=ubuntu\nVERSION_CODENAME=resolute\n");
    $guard = new GatewayOperatingSystemGuard($path);

    try {
        expect(fn () => $guard->assertSupported())->not->toThrow(NodeProvisioningException::class);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('accepts single-quoted os-release values', function (): void {
    $path = gateway_os_release_fixture("ID='ubuntu'\nVERSION_CODENAME='resolute'\n");
    $guard = new GatewayOperatingSystemGuard($path);

    try {
        expect(fn () => $guard->assertSupported())->not->toThrow(NodeProvisioningException::class);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('accepts double-quoted os-release values', function (): void {
    $path = gateway_os_release_fixture("ID=\"ubuntu\"\nVERSION_CODENAME=\"resolute\"\n");
    $guard = new GatewayOperatingSystemGuard($path);

    try {
        expect(fn () => $guard->assertSupported())->not->toThrow(NodeProvisioningException::class);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('accepts os-release metadata without a trailing newline', function (): void {
    $path = gateway_os_release_fixture("ID=ubuntu\nVERSION_CODENAME=resolute");
    $guard = new GatewayOperatingSystemGuard($path);

    try {
        expect(fn () => $guard->assertSupported())->not->toThrow(NodeProvisioningException::class);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('rejects unsupported local gateway operating systems', function (string $contents): void {
    $path = gateway_os_release_fixture($contents);
    $guard = new GatewayOperatingSystemGuard($path);

    try {
        expect(fn () => $guard->assertSupported())
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('operating-system')
                    ->and($exception->errorCode)
                    ->toBe('gateway.operating_system_unsupported')
                    ->and($exception->getMessage())
                    ->toBe('Gateway bootstrap requires Ubuntu 26.04 Resolute.');
            });
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
})->with([
    'Ubuntu Noble' => "ID=ubuntu\nVERSION_CODENAME=noble\n",
    'Debian' => "ID=debian\nVERSION_CODENAME=resolute\n",
    'unknown release' => "ID=ubuntu\nVERSION_CODENAME=jammy\n",
    'malformed release' => "ID=ubuntu\nVERSION_CODENAME='resolute extra'\n",
    'mismatched ID quotes' => "ID=\"ubuntu'\nVERSION_CODENAME=resolute\n",
    'mismatched codename quotes' => "ID=ubuntu\nVERSION_CODENAME=\"resolute'\n",
    'empty ID' => "ID=\nVERSION_CODENAME=resolute\n",
    'empty codename' => "ID=ubuntu\nVERSION_CODENAME=\"\"\n",
    'unsafe command substitution' => "ID=\$(printf ubuntu)\nVERSION_CODENAME=resolute\n",
    'unsafe backtick' => "ID=`printf ubuntu`\nVERSION_CODENAME=resolute\n",
    'unsafe semicolon' => "ID=ubuntu;touch /tmp/pwned\nVERSION_CODENAME=resolute\n",
    'duplicate ID' => "ID=ubuntu\nID=ubuntu\nVERSION_CODENAME=resolute\n",
    'duplicate codename' => "ID=ubuntu\nVERSION_CODENAME=resolute\nVERSION_CODENAME=resolute\n",
    'duplicate target after malformed value' => "ID=ubuntu\nID=\"\nVERSION_CODENAME=resolute\n",
    'duplicate target before malformed value' => "ID=\"\nID=ubuntu\nVERSION_CODENAME=resolute\n",
]);

it('rejects missing or unreadable operating system metadata', function (): void {
    $guard = new GatewayOperatingSystemGuard('/tmp/'.Str::uuid().'-missing-os-release');

    expect(fn () => $guard->assertSupported())
        ->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->step)
                ->toBe('operating-system')
                ->and($exception->errorCode)
                ->toBe('gateway.operating_system_unsupported');
        });
});

function gateway_os_release_fixture(string $contents): string
{
    $path = sys_get_temp_dir().'/orbit-gateway-os-release-'.Str::uuid();
    file_put_contents($path, $contents);

    return $path;
}
