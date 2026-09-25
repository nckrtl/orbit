<?php

declare(strict_types=1);

use Tests\Support\TestOrbitHome;

it('uses ORBIT_HOME as the portable mutable data root', function (): void {
    expect(config('orbit.home'))->toBe(TestOrbitHome::path())
        ->and(getenv('ORBIT_HOME'))->toBe(TestOrbitHome::path());
});

it('gives each test process its own ORBIT_HOME in the system temporary directory', function (): void {
    $home = TestOrbitHome::path();

    expect($home)->toStartWith(realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.TestOrbitHome::Prefix.getmypid().'-')
        ->and(is_dir($home))->toBeTrue()
        ->and(fileperms($home) & 0o777)->toBe(0o700);
});
