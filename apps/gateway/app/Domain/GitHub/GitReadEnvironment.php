<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use LogicException;
use SensitiveParameter;

/**
 * Environment variables that configure Git for one read. They authenticate HTTPS requests to
 * `github.com` and make Git reach an SSH-form `github.com` origin over HTTPS without changing the
 * origin that Git stores.
 */
final readonly class GitReadEnvironment
{
    /** @param array<string, string> $variables */
    private function __construct(
        #[SensitiveParameter]
        public array $variables,
    ) {}

    public static function none(): self
    {
        return new self([]);
    }

    public static function forGitHubToken(#[SensitiveParameter] string $token): self
    {
        $entries = [
            ['http.https://github.com/.extraheader', 'Authorization: Basic '.base64_encode("x-access-token:{$token}")],
            ['url.https://github.com/.insteadOf', 'git@github.com:'],
            ['url.https://github.com/.insteadOf', 'ssh://git@github.com/'],
        ];

        $variables = ['GIT_CONFIG_COUNT' => (string) count($entries)];

        foreach ($entries as $index => [$key, $value]) {
            $variables["GIT_CONFIG_KEY_{$index}"] = $key;
            $variables["GIT_CONFIG_VALUE_{$index}"] = $value;
        }

        return new self($variables);
    }

    public function isEmpty(): bool
    {
        return $this->variables === [];
    }

    /**
     * Bash that defines `git_read`, which runs one command with the variables set, and
     * `$git_read_sudo`, the `sudo` option that carries them to a command run as another user. It
     * belongs at the start of a script that travels on standard input. Only the wrapped command
     * sees the variables, so other Git commands in the script still read the stored origin. Values
     * are single-quoted, and none of them contains a single quote.
     */
    public function bashPreamble(): string
    {
        if ($this->variables === []) {
            return "git_read() ( exec \"\$@\" )\ngit_read_sudo=\n";
        }

        $lines = ['git_read() ('];

        foreach ($this->variables as $name => $value) {
            if (str_contains($value, "'") || str_contains($value, "\n")) {
                throw new LogicException('A Git read variable cannot be quoted for Bash.');
            }

            $lines[] = "    export {$name}='{$value}'";
        }

        $lines[] = '    exec "$@"';
        $lines[] = ')';
        $lines[] = 'git_read_sudo=--preserve-env='.implode(',', array_keys($this->variables));

        return implode("\n", $lines)."\n";
    }

    /** @return array{type: class-string} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }
}
