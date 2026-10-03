<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\AppDev\AnnotatorEndpoint;

final readonly class AnnotatorPreset
{
    public const string NAME = 'annotator';

    public const string DIRECTORY = '/opt/orbit/annotator/current';

    /** @return list<string> */
    public static function command(): array
    {
        return ['/usr/local/bin/node', self::DIRECTORY.'/bin/serve.mjs', 'serve'];
    }

    /** @return list<string> */
    public static function forTarget(ProcessTarget $target): array
    {
        if ($target->instance?->annotator_port === null) {
            throw new \InvalidArgumentException('The annotator requires an assigned port.');
        }

        return [...self::command(), '--port', (string) $target->instance->annotator_port, '--store', AnnotatorEndpoint::store($target->instance->id), '--allow-origin', 'https://'.($target->routeDomain ?? 'unrouted.invalid'), '--allow-origin', 't3code://app', '--allow-origin', 't3code-dev://app'];
    }
}
