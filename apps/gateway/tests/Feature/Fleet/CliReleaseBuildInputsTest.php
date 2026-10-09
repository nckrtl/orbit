<?php

declare(strict_types=1);

use App\Domain\Fleet\CliReleaseName;

/** The fallback trusts that equal build inputs build the same CLI, so every input the build reads must be listed. */
it('lists every repository path the CLI build reads', function (): void {
    $root = dirname(base_path(), 2);
    $covered = static fn (string $path): bool => array_any(
        CliReleaseName::BuildInputs,
        static fn (string $input): bool => $path === $input || str_starts_with($path, $input.'/'),
    );
    $builder = (string) file_get_contents($root.'/bin/orbit-build-cli-binary');
    $workflow = (string) file_get_contents($root.'/.github/workflows/orbit-cli-binary.yml');
    preg_match_all('#\$repo_root/([A-Za-z0-9._/-]+)#', $builder, $fromBuilder);
    preg_match_all('#(?<![A-Za-z0-9_./-])((?:bin|apps|packages)/[A-Za-z0-9._/-]+)#', $workflow, $fromWorkflow);
    $paths = array_values(array_unique([...$fromBuilder[1], ...$fromWorkflow[1], '.github/workflows/orbit-cli-binary.yml']));

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        expect($covered($path))->toBeTrue("CliReleaseName::BuildInputs does not cover {$path}.");
    }

    foreach (CliReleaseName::BuildInputs as $input) {
        expect(file_exists($root.'/'.$input))->toBeTrue("{$input} does not exist.");
    }
});
