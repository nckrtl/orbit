<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use InvalidArgumentException;
use JsonException;

/** Reads one exact guest argv from `--argv` or `--argv-file`, shared by `exec` and `spawn`. */
trait ReadsGuestArgv
{
    /**
     * The argv vector comes from exactly one of `--argv` (an inline JSON array of
     * strings, no stdin) or `--argv-file` (a file holding `{"argv":[...],"stdin":null}`).
     *
     * @return array{list<string>, ?string}
     */
    protected function commandInput(): array
    {
        $inline = $this->option('argv');
        $path = $this->option('argv-file');
        $hasInline = is_string($inline) && $inline !== '';
        $hasFile = is_string($path) && $path !== '';
        if ($hasInline && $hasFile) {
            throw new InvalidArgumentException('Use either --argv or --argv-file, not both.');
        }
        if (! $hasInline && ! $hasFile) {
            throw new InvalidArgumentException(
                'An exact argv JSON array (--argv) or argv JSON file (--argv-file) is required.',
            );
        }
        if ($hasInline) {
            try {
                $value = json_decode($inline, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException(
                    'The --argv value must be a JSON array of strings, for example \'["orbit","doctor","--json"]\'.',
                    previous: $exception,
                );
            }
            if (! is_array($value) || ! array_is_list($value) || $value === []) {
                throw new InvalidArgumentException(
                    'The --argv value must be a non-empty JSON array of strings, for example \'["orbit","doctor","--json"]\'.',
                );
            }

            return [$this->argvList($value), null];
        }
        if (! is_file($path) || is_link($path)) {
            throw new InvalidArgumentException('An exact argv JSON file is required.');
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The argv JSON file is malformed.', previous: $exception);
        }
        if (
            ! is_array($value)
            || array_keys($value) !== ['argv', 'stdin']
            || ! is_array($value['argv'])
            || ! array_is_list($value['argv'])
            || $value['stdin'] !== null
            && ! is_string($value['stdin'])
        ) {
            throw new InvalidArgumentException('The argv JSON schema is invalid.');
        }

        return [$this->argvList($value['argv']), $value['stdin']];
    }

    /**
     * @param  list<mixed>  $value
     * @return list<string>
     */
    protected function argvList(array $value): array
    {
        $argv = [];
        foreach ($value as $argument) {
            if (! is_string($argument)) {
                throw new InvalidArgumentException('Every argv item must be a string.');
            }
            $argv[] = $argument;
        }

        return $argv;
    }
}
