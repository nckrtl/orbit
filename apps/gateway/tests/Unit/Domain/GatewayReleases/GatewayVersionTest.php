<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayVersion;
use Illuminate\Support\Str;

describe(GatewayVersion::class, function (): void {
    beforeEach(function (): void {
        $this->revision = sys_get_temp_dir().'/orbit-revision-'.Str::random(8);
    });

    afterEach(function (): void {
        @unlink($this->revision);
    });

    it('reports APP_VERSION when it is set', function (): void {
        file_put_contents($this->revision, str_repeat('a', 40)."\n");

        expect(GatewayVersion::resolve('1.2.3', $this->revision))->toBe('1.2.3');
    });

    it('falls back to the release REVISION commit when APP_VERSION is unset or empty', function (mixed $configured): void {
        file_put_contents($this->revision, str_repeat('b', 40)."\n");

        expect(GatewayVersion::resolve($configured, $this->revision))->toBe(str_repeat('b', 40));
    })->with([null, '', '  ']);

    it('reports dev without a REVISION or with one that is not a commit', function (?string $contents): void {
        if ($contents !== null) {
            file_put_contents($this->revision, $contents);
        }

        expect(GatewayVersion::resolve(null, $this->revision))->toBe('dev');
    })->with([null, 'main', '']);
});
