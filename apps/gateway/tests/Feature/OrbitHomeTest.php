<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\TestOrbitHome;

function orbit_home_probe(): Process
{
    $script = 'require '.var_export(base_path('vendor/autoload.php'), true).';'
        .' Tests\Support\TestOrbitHome::bootstrap();'
        .' fwrite(STDOUT, Tests\Support\TestOrbitHome::path().PHP_EOL);'
        .' sleep(30);';

    return new Process([PHP_BINARY, '-r', $script], base_path(), timeout: 30);
}

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

it('keeps scratch paths inside this process home', function (): void {
    $scratch = TestOrbitHome::scratch('orbit-example');
    mkdir($scratch, 0o700, true);

    expect($scratch)->toStartWith(TestOrbitHome::path().DIRECTORY_SEPARATOR.'scratch'.DIRECTORY_SEPARATOR.'orbit-example-')
        ->and(TestOrbitHome::scratch('orbit-example'))->not->toBe($scratch);

    TestOrbitHome::clearScratch();

    expect(is_dir($scratch))->toBeFalse()
        ->and(is_dir(TestOrbitHome::path()))->toBeTrue();
});

it('removes the home when the process is interrupted', function (int $signal): void {
    $probe = orbit_home_probe();
    $probe->start();
    $probe->waitUntil(static fn (string $type, string $output): bool => str_contains($output, PHP_EOL));
    $home = trim($probe->getOutput());

    expect(is_dir($home))->toBeTrue();

    $probe->signal($signal);
    $probe->wait();

    expect(is_dir($home))->toBeFalse()
        ->and($probe->getTermSignal())->toBe($signal);
})->with(['SIGINT' => SIGINT, 'SIGTERM' => SIGTERM])->skip(! function_exists('pcntl_signal'), 'Signal cleanup needs ext-pcntl.');

it('removes homes whose process no longer runs and keeps homes of running processes', function (): void {
    $finished = new Process(['sleep', '0.1']);
    $finished->start();
    $finishedPid = $finished->getPid();
    $finished->wait();
    $abandoned = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.TestOrbitHome::Prefix.$finishedPid.'-0badc0de';
    mkdir($abandoned, 0o700);

    $probe = orbit_home_probe();
    $probe->start();
    $probe->waitUntil(static fn (string $type, string $output): bool => str_contains($output, PHP_EOL));
    $probe->stop(0);

    expect(is_dir($abandoned))->toBeFalse()
        ->and(is_dir(TestOrbitHome::path()))->toBeTrue();
})->skip(! function_exists('posix_kill'), 'Abandoned home cleanup needs ext-posix.');
