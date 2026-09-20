<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitReadEnvironment;
use App\Infrastructure\Processes\ProtectedInput;

/**
 * A Bash script that reads a repository, with the `git_read` preamble in front. A script that
 * carries a token travels as protected input, so the token exists only on the standard input of the
 * SSH process. Pass both properties to the remote command; one of them is null.
 */
final readonly class GitReadScript
{
    private function __construct(
        public ?string $input,
        public ?ProtectedInput $protectedInput,
    ) {}

    public static function for(GitReadEnvironment $environment, string $script): self
    {
        $script = $environment->bashPreamble().$script;

        return $environment->isEmpty()
            ? new self($script, null)
            : new self(null, ProtectedInput::fromString($script));
    }
}
