<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use PhpToken;
use RuntimeException;

/**
 * The private DNS listener as a self-contained release: `serve.php` plus every Gateway class the listener process
 * reaches, copied from the Gateway's own source. Its id is a digest of those files, so it changes exactly when the
 * listener code changes. See ADR 0148.
 */
final class PrivateDnsListenerRelease
{
    private const string Entry = PrivateDnsListenerProcess::class;

    /** @var array<string, string>|null */
    private ?array $files = null;

    public function __construct(
        private readonly string $sourceRoot,
    ) {}

    public static function fromGateway(): self
    {
        return new self(base_path());
    }

    /**
     * @return array<string, string> Release-relative path => contents, sorted by path.
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $entry = $this->read('resources/private-dns/serve.php');
        $files = ['serve.php' => $entry];
        $pending = [self::Entry];
        $seen = [];

        while ($pending !== []) {
            $class = array_pop($pending);
            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;
            $path = 'app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            $source = $this->read($path);
            $files[$path] = $source;

            foreach ($this->references($source) as $reference) {
                if (! isset($seen[$reference]) && $this->exists($this->classPath($reference))) {
                    $pending[] = $reference;
                }
            }
        }

        ksort($files, SORT_STRING);

        return $this->files = $files;
    }

    public function id(): string
    {
        $manifest = '';
        foreach ($this->files() as $path => $contents) {
            $manifest .= $path."\0".hash('sha256', $contents)."\n";
        }

        return substr(hash('sha256', $manifest), 0, 16);
    }

    /**
     * App classes the source names: imports, qualified names, and bare names in its own namespace.
     *
     * @return list<string>
     */
    private function references(string $source): array
    {
        $namespace = '';
        $imports = [];
        $names = [];
        $tokens = PhpToken::tokenize($source);
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token->is(T_NAMESPACE)) {
                $namespace = $this->nextName($tokens, $index) ?? '';

                continue;
            }

            if ($token->is(T_USE) && $this->atTopLevel($tokens, $index)) {
                $name = $this->nextName($tokens, $index);
                if ($name !== null) {
                    $imports[substr($name, (int) strrpos('\\'.$name, '\\'))] = $name;
                }

                continue;
            }

            if ($token->is([T_NAME_FULLY_QUALIFIED])) {
                $names[] = ltrim($token->text, '\\');
            } elseif ($token->is([T_NAME_QUALIFIED])) {
                $first = strstr($token->text, '\\', true);
                $names[] = isset($imports[$first])
                    ? $imports[$first].substr($token->text, strlen($first))
                    : $namespace.'\\'.$token->text;
            } elseif ($token->is(T_STRING)) {
                $names[] = $imports[$token->text] ?? $namespace.'\\'.$token->text;
            }
        }

        return array_values(array_unique(array_filter(
            [...$names, ...array_values($imports)],
            static fn (string $name): bool => str_starts_with($name, 'App\\'),
        )));
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function nextName(array $tokens, int &$index): ?string
    {
        for ($next = $index + 1, $count = count($tokens); $next < $count; $next++) {
            if ($tokens[$next]->isIgnorable()) {
                continue;
            }

            if ($tokens[$next]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $index = $next;

                return ltrim($tokens[$next]->text, '\\');
            }

            return null;
        }

        return null;
    }

    /**
     * A `use` before the first class body imports a name. A `use` inside a class body imports a trait, which a
     * bare-name reference already covers.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function atTopLevel(array $tokens, int $index): bool
    {
        $depth = 0;
        for ($previous = 0; $previous < $index; $previous++) {
            if ($tokens[$previous]->text === '{' || $tokens[$previous]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($tokens[$previous]->text === '}') {
                $depth--;
            }
        }

        return $depth === 0;
    }

    private function classPath(string $class): string
    {
        return 'app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    }

    /**
     * An exact, case-sensitive match, so the release is the same on every file system.
     */
    private function exists(string $path): bool
    {
        $directory = $this->sourceRoot.'/'.dirname($path);
        $entries = is_dir($directory) ? scandir($directory) : false;

        return is_array($entries) && in_array(basename($path), $entries, true);
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($this->sourceRoot.'/'.$path);
        if (! is_string($contents)) {
            throw new RuntimeException("Could not read the private DNS listener source [{$path}].");
        }

        return $contents;
    }
}
