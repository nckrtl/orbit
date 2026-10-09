<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\TestOrbitHome;
use Tests\Support\TestTemporaryDirectory;

pest()->group('subprocess');

function test_temporary_directory_probe(string $tmpdir): Process
{
    $script = 'require '.var_export(base_path('vendor/autoload.php'), true).';'
        .' Tests\Support\TestTemporaryDirectory::bootstrap();'
        .' mkdir(sys_get_temp_dir()."/fixture");'
        .' fwrite(STDOUT, sys_get_temp_dir().PHP_EOL);';

    return new Process([PHP_BINARY, '-r', $script], base_path(), ['TMPDIR' => $tmpdir], timeout: 30);
}

it('gives a test process its own directory, removes it at exit, and removes those of ended processes', function (): void {
    $base = TestOrbitHome::scratch('temporary-base');
    mkdir($base, 0o755, true);
    $finished = new Process(['sleep', '0.1']);
    $finished->start();
    $finishedPid = $finished->getPid();
    $finished->wait();
    $abandoned = $base.'/'.TestTemporaryDirectory::Prefix.$finishedPid.'-0badc0de';
    $running = $base.'/'.TestTemporaryDirectory::Prefix.getmypid().'-0badc0de';
    mkdir($abandoned.'/fixture', 0o755, true);
    mkdir($running);

    $directory = trim(test_temporary_directory_probe($base)->mustRun()->getOutput());

    expect($directory)->toStartWith(realpath($base).'/'.TestTemporaryDirectory::Prefix)
        ->and(is_dir($directory))->toBeFalse()
        ->and(is_dir($abandoned))->toBeFalse()
        ->and(is_dir($running))->toBeTrue();
})->skip(! function_exists('posix_kill'), 'Abandoned directory cleanup needs ext-posix.');

it('lets a child test process reuse the directory it inherits and leaves its removal to the owner', function (): void {
    $inherited = sys_get_temp_dir();

    expect(basename($inherited))->toStartWith(TestTemporaryDirectory::Prefix)
        ->and(trim(test_temporary_directory_probe($inherited)->mustRun()->getOutput()))->toBe($inherited)
        ->and(is_dir($inherited.'/fixture'))->toBeTrue();

    rmdir($inherited.'/fixture');
});
