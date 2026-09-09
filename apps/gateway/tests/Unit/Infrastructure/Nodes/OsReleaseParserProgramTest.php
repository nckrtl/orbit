<?php

declare(strict_types=1);

use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Nodes\OsReleaseParserProgram;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('returns the selected codename and consumes only its arguments', function (string $contents): void {
    [$process, $root] = run_os_release_parser($contents, ['resolute'], ['payload-one', 'payload-two']);

    try {
        expect($process->isSuccessful())
            ->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())
            ->toBe("resolute\npayload-one\npayload-two\n");
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'bare values' => "ID=ubuntu\nVERSION_CODENAME=resolute\n",
    'single quoted values' => "ID='ubuntu'\nVERSION_CODENAME='resolute'\n",
    'double quoted values' => "ID=\"ubuntu\"\nVERSION_CODENAME=\"resolute\"\n",
    'final line without newline' => "ID=ubuntu\nVERSION_CODENAME=resolute",
]);

it('rejects unsafe release metadata without executing it', function (string $contents): void {
    $payload = 'orbit-os-release-payload-'.Str::uuid();
    $marker = sys_get_temp_dir().'/'.$payload;
    [$process, $root] = run_os_release_parser(str_replace('__PAYLOAD__', $marker, $contents));

    try {
        expect($process->isSuccessful())
            ->toBeFalse()
            ->and($process->getOutput())
            ->toBeEmpty()
            ->and($process->getErrorOutput())
            ->toBe(UbuntuRelease::unsupportedText()."\n")
            ->and(file_exists($marker))
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($root);

        if (is_file($marker)) {
            unlink($marker);
        }
    }
})->with([
    'duplicate ID' => "ID=ubuntu\nID=ubuntu\nVERSION_CODENAME=resolute\n",
    'duplicate codename' => "ID=ubuntu\nVERSION_CODENAME=resolute\nVERSION_CODENAME=resolute\n",
    'missing ID' => "VERSION_CODENAME=resolute\n",
    'empty codename' => "ID=ubuntu\nVERSION_CODENAME=\n",
    'mismatched quotes' => "ID=ubuntu\nVERSION_CODENAME=\"resolute'\n",
    'command substitution' => "ID=ubuntu\nVERSION_CODENAME=\$(touch __PAYLOAD__)\n",
    'backticks' => "ID=ubuntu\nVERSION_CODENAME=`touch __PAYLOAD__`\n",
    'semicolon' => "ID=ubuntu\nVERSION_CODENAME=resolute;touch __PAYLOAD__\n",
]);

it('reports a safely parsed unsupported release', function (): void {
    [$process, $root] = run_os_release_parser("ID=debian\nVERSION_CODENAME=bookworm\n");

    try {
        expect($process->isSuccessful())
            ->toBeFalse()
            ->and($process->getErrorOutput())
            ->toBe(UbuntuRelease::unsupportedText('debian', 'bookworm')."\n");
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

/**
 * @param  list<string>  $allowedCodenames
 * @param  list<string>  $remainingArguments
 * @return array{Process, non-empty-string}
 */
function run_os_release_parser(
    string $contents,
    array $allowedCodenames = ['resolute'],
    array $remainingArguments = [],
): array {
    $root = sys_get_temp_dir().'/orbit-os-release-parser-'.Str::uuid();
    mkdir($root.'/etc', 0o755, true);
    file_put_contents($root.'/etc/os-release', $contents);

    $script =
        str_replace(
            '/etc/os-release',
            $root.'/etc/os-release',
            OsReleaseParserProgram::render(),
        ).<<<'BASH'

            printf '%s\n' "$selected_codename"
            printf '%s\n' "$@"
            BASH;
    $process = new Process([
        'bash',
        '-seu',
        '--',
        'ubuntu',
        UbuntuRelease::unsupportedText(),
        (string) count($allowedCodenames),
        ...$allowedCodenames,
        ...$remainingArguments,
    ]);
    $process->setInput($script);
    $process->run();

    /** @var non-empty-string $root */
    return [$process, $root];
}
