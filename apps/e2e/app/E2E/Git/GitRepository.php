<?php

declare(strict_types=1);

namespace App\E2E\Git;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity,kan-defect Repository validation stays at the Git trust boundary. */
/** @mago-expect lint:too-many-methods The Git trust boundary owns the locked repository operations. */
final readonly class GitRepository
{
    public function __construct(
        private string $path,
    ) {}

    public function root(): string
    {
        $root = realpath(trim($this->run(['rev-parse', '--show-toplevel'])));

        if ($root === false) {
            throw new InvalidArgumentException('The Git repository root does not exist.');
        }

        return $root;
    }

    public function branch(): string
    {
        $branch = trim($this->run(['symbolic-ref', '--quiet', '--short', 'HEAD']));

        if ($branch === '' || preg_match('/[\0\r\n]/', $branch) === 1) {
            throw new InvalidArgumentException('The Git branch is invalid.');
        }

        return $branch;
    }

    /** The shared Git directory every linked worktree of one repository resolves to. */
    public function commonDirectory(): string
    {
        $commonDirectory = realpath(trim($this->run([
            'rev-parse',
            '--path-format=absolute',
            '--git-common-dir',
        ])));

        if ($commonDirectory === false) {
            throw new InvalidArgumentException('The Git directories do not exist.');
        }

        return $commonDirectory;
    }

    public function isLinkedWorktree(): bool
    {
        $gitDirectory = realpath(trim($this->run([
            'rev-parse',
            '--path-format=absolute',
            '--git-dir',
        ])));
        $commonDirectory = realpath(trim($this->run([
            'rev-parse',
            '--path-format=absolute',
            '--git-common-dir',
        ])));

        if ($gitDirectory === false || $commonDirectory === false) {
            throw new InvalidArgumentException('The Git directories do not exist.');
        }

        return $gitDirectory !== $commonDirectory;
    }

    public function hasCommit(string $commit): bool
    {
        $this->validateSha($commit);
        $result = Process::path($this->path)->run(['git', 'cat-file', '-e', "{$commit}^{commit}"]);

        if ($result->exitCode() === 0) {
            return true;
        }
        if ($result->exitCode() === 1) {
            return false;
        }

        throw new InvalidArgumentException('Git could not verify the bundle prerequisite commit.');
    }

    /** Whether `$ancestor` is reachable from `$descendant` (a commit is its own ancestor). */
    public function isAncestor(string $ancestor, string $descendant): bool
    {
        $this->validateSha($ancestor);
        $this->validateSha($descendant);
        $result = Process::path($this->path)->run(['git', 'merge-base', '--is-ancestor', $ancestor, $descendant]);

        if ($result->exitCode() === 0) {
            return true;
        }
        if ($result->exitCode() === 1) {
            return false;
        }

        throw new InvalidArgumentException('Git could not compare the commit ancestry.');
    }

    public function createBundle(string $destination, string $commit, ?string $prerequisite = null): void
    {
        $this->validateSha($commit);
        if ($prerequisite !== null) {
            $this->validateSha($prerequisite);
        }
        if ($destination === '' || str_contains($destination, "\0")) {
            throw new InvalidArgumentException('The bundle destination is invalid.');
        }

        $ref = 'refs/orbit/e2e-source/'.bin2hex(random_bytes(16));
        $this->run(['update-ref', $ref, $commit, str_repeat('0', 40)]);
        try {
            $arguments = ['bundle', 'create', $destination, $ref];
            if ($prerequisite !== null && $prerequisite !== $commit) {
                $arguments[] = "^{$prerequisite}";
            }
            $this->run($arguments);
        } finally {
            $this->run(['update-ref', '-d', $ref, $commit]);
        }
    }

    public function dirtyOverlay(): ?\App\E2E\Value\DirtyOverlay
    {
        $records = $this->run(['status', '--porcelain=v1', '-z', '--untracked-files=all', '--ignore-submodules=none']);
        if ($records === '') {
            return null;
        }

        $paths = [];
        $parts = explode("\0", $records);
        for ($index = 0; $index < count($parts); $index++) {
            $record = $parts[$index];
            if ($record === '') {
                continue;
            }
            if (strlen($record) < 4 || $record[2] !== ' ') {
                throw new InvalidArgumentException('Git returned an invalid dirty inventory.');
            }
            $status = substr($record, 0, 2);
            if (str_contains($status, 'U') || in_array($status, ['AA', 'DD'], true)) {
                throw new InvalidArgumentException('Merge conflicts cannot be synchronized.');
            }
            $path = substr($record, 3);
            if ($status[0] === 'R' || $status[0] === 'C' || $status[1] === 'R' || $status[1] === 'C') {
                $previousPath = $parts[++$index] ?? '';
                $this->validateOverlayPath($previousPath);
                $paths[] = $previousPath;
            }
            $this->validateOverlayPath($path);
            $this->validateOverlayEntry($path, $status);
            $paths[] = $path;
        }

        sort($paths, SORT_STRING);

        return new \App\E2E\Value\DirtyOverlay(array_values(array_unique($paths)), $this->effectiveTreeHash());
    }

    public function effectiveTreeHash(): string
    {
        $index = tempnam(sys_get_temp_dir(), 'orbit-index-');
        if ($index === false) {
            throw new RuntimeException('Could not create the temporary Git index.');
        }
        unlink($index);

        try {
            $environment = ['GIT_INDEX_FILE' => $index];
            foreach ([['read-tree', 'HEAD'], ['add', '-A', '--', '.']] as $arguments) {
                $result = Process::path($this->path)->env($environment)->run(['git', ...$arguments]);
                if ($result->failed()) {
                    throw new InvalidArgumentException('Git could not calculate the effective tree.');
                }
            }
            $result = Process::path($this->path)->env($environment)->run(['git', 'write-tree']);
            if ($result->failed() || preg_match('/\A[0-9a-f]{40}\n?\z/D', $result->output()) !== 1) {
                throw new InvalidArgumentException('Git returned an invalid effective tree.');
            }
            $hash = hash('sha256', trim($result->output()));
        } catch (\Throwable $exception) {
            if (file_exists($index) && ! unlink($index)) {
                throw new RuntimeException('Could not remove the temporary Git index.', previous: $exception);
            }

            throw $exception;
        }

        if (file_exists($index) && ! unlink($index)) {
            throw new RuntimeException('Could not remove the temporary Git index.');
        }

        return $hash;
    }

    /** @param list<string> $paths */
    public function createOverlayArchive(string $destination, array $paths): void
    {
        $files = [];
        foreach ($paths as $path) {
            $this->validateOverlayPath($path);
            $absolute = $this->root().'/'.$path;
            if (is_file($absolute)) {
                $this->validateOverlayEntry($path, '??');
                $files[] = $path;
            }
        }
        if ($files === []) {
            file_put_contents($destination, '');

            return;
        }
        $result = Process::path($this->root())->run([
            'tar',
            '--format=ustar',
            '--no-recursion',
            '-cf',
            $destination,
            '--',
            ...$files,
        ]);
        if ($result->failed()) {
            throw new InvalidArgumentException('The dirty overlay archive could not be created.');
        }
    }

    private function validateSha(string $sha): void
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
            throw new InvalidArgumentException('The commit must be an exact full SHA.');
        }
    }

    private function validateOverlayPath(string $path): void
    {
        new \App\E2E\Value\DirtyOverlay([$path], str_repeat('0', 64));
        if (
            preg_match('~(?:\A|/)(?:\.git|vendor|node_modules)(?:/|\z)~i', $path) === 1
            || preg_match('~(?:\A|/)(?:\.env(?:\.|\z)|credentials?(?:\.|/|\z)|id_[rd]sa(?:\.|\z))~i', $path) === 1
        ) {
            throw new InvalidArgumentException("Dirty path [{$path}] is prohibited.");
        }
    }

    private function validateOverlayEntry(string $path, string $status): void
    {
        if (str_contains($status, 'D')) {
            return;
        }
        $absolute = $this->root().'/'.$path;
        $parent = dirname($absolute);
        while ($parent !== $this->root()) {
            if (is_link($parent)) {
                throw new InvalidArgumentException('Dirty paths cannot traverse a symlink parent.');
            }

            $next = dirname($parent);
            if ($next === $parent || ! str_starts_with($next.'/', $this->root().'/')) {
                throw new InvalidArgumentException('Dirty path parent escaped the repository.');
            }
            $parent = $next;
        }
        if (is_link($absolute)) {
            throw new InvalidArgumentException('Dirty symlinks cannot be synchronized.');
        }
        if (! is_file($absolute)) {
            throw new InvalidArgumentException('Dirty entries must be regular files.');
        }
        $staged = trim($this->run(['ls-files', '--stage', '--', $path]));
        if (str_starts_with($staged, '160000 ')) {
            throw new InvalidArgumentException('Submodules cannot be synchronized.');
        }
    }

    public function commit(string $revision = 'HEAD'): string
    {
        if ($revision === '' || str_contains($revision, "\0") || preg_match('/[\r\n]/', $revision) === 1) {
            throw new InvalidArgumentException('The Git revision is invalid.');
        }

        $commit = strtolower(trim($this->run(['rev-parse', '--verify', "{$revision}^{commit}"])));

        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new InvalidArgumentException('Git did not return a full commit SHA.');
        }

        return $commit;
    }

    /** The tree object of one exact commit that a repository reference still reaches. */
    public function tree(string $commit): string
    {
        $this->validateReachableCommit($commit);
        $tree = strtolower(trim($this->run(['rev-parse', '--verify', "{$commit}^{tree}"])));

        if (preg_match('/\A[0-9a-f]{40}\z/D', $tree) !== 1) {
            throw new InvalidArgumentException('Git did not return a full tree SHA.');
        }

        return $tree;
    }

    /** @param list<string> $patterns
     * @return array<string, string>
     */
    public function blobs(string $commit, array $patterns): array
    {
        $this->validateReachableCommit($commit);

        if ($patterns === []) {
            throw new InvalidArgumentException('At least one repository path pattern is required.');
        }

        foreach ($patterns as $pattern) {
            $this->validatePattern($pattern);
        }

        $entries = $this->treeEntries($commit);
        $selected = [];

        foreach ($patterns as $pattern) {
            $matched = false;

            foreach ($entries as $path => $entry) {
                if (! $this->matches($pattern, $path)) {
                    continue;
                }

                $matched = true;

                if ($entry['mode'] === '120000' || $entry['type'] !== 'blob') {
                    throw new InvalidArgumentException("Prepared input [{$path}] is not a regular file.");
                }

                $selected[$path] = $entry['object'];
            }

            if (! $matched) {
                throw new InvalidArgumentException("Prepared input pattern [{$pattern}] did not match a file.");
            }
        }

        ksort($selected, SORT_STRING);

        return $this->readBlobs($selected);
    }

    /**
     * Every regular file directly under one directory of an exact commit, with
     * its tree mode and content. An absent directory is an empty inventory; a
     * nested directory, symlink, or submodule below it is refused.
     *
     * @return array<string, array{mode:string, content:string}> Keyed by file name.
     */
    public function directoryBlobs(string $commit, string $directory): array
    {
        $this->validateReachableCommit($commit);
        $this->validatePattern($directory);
        $prefix = rtrim($directory, '/').'/';
        $selected = [];
        $modes = [];

        foreach ($this->treeEntries($commit) as $path => $entry) {
            if (! str_starts_with($path, $prefix)) {
                continue;
            }
            $name = substr($path, strlen($prefix));
            if (str_contains($name, '/') || $entry['mode'] === '120000' || $entry['type'] !== 'blob') {
                throw new InvalidArgumentException("Directory entry [{$path}] is not a regular file.");
            }
            $selected[$name] = $entry['object'];
            $modes[$name] = $entry['mode'];
        }

        $blobs = [];
        foreach ($selected === [] ? [] : $this->readBlobs($selected) as $name => $content) {
            $blobs[$name] = ['mode' => $modes[$name], 'content' => $content];
        }

        return $blobs;
    }

    /**
     * @param array<string, string> $selected
     * @return array<string, string>
     */
    private function readBlobs(array $selected): array
    {
        $result = Process::path($this->path)
            ->input(implode("\n", array_values($selected))."\n")
            ->run(['git', 'cat-file', '--batch']);
        if ($result->failed()) {
            throw new InvalidArgumentException('The Git command failed.');
        }

        $output = $result->output();
        $offset = 0;
        $blobs = [];
        foreach ($selected as $path => $expectedObject) {
            $headerEnd = strpos($output, "\n", $offset);
            if ($headerEnd === false) {
                throw new InvalidArgumentException('Git returned an invalid blob batch.');
            }
            $header = substr($output, $offset, $headerEnd - $offset);
            if (
                preg_match('/\A([0-9a-f]{40}) blob ([0-9]+)\z/D', $header, $matches) !== 1
                || $matches[1] !== $expectedObject
            ) {
                throw new InvalidArgumentException('Git returned an invalid blob batch.');
            }
            $length = (int) $matches[2];
            $contentStart = $headerEnd + 1;
            $delimiter = $contentStart + $length;
            if ($delimiter >= strlen($output) || $output[$delimiter] !== "\n") {
                throw new InvalidArgumentException('Git returned an invalid blob batch.');
            }
            $blobs[$path] = substr($output, $contentStart, $length);
            $offset = $delimiter + 1;
        }
        if ($offset !== strlen($output)) {
            throw new InvalidArgumentException('Git returned an invalid blob batch.');
        }

        return $blobs;
    }

    private function validateReachableCommit(string $commit): void
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1 || $this->commit($commit) !== $commit) {
            throw new InvalidArgumentException('The commit must be an exact full SHA.');
        }

        $references = trim($this->run([
            'for-each-ref',
            "--contains={$commit}",
            '--format=%(refname)',
            'refs/heads',
            'refs/remotes',
            'refs/tags',
        ]));

        if ($references === '') {
            throw new InvalidArgumentException('The commit is not reachable from a repository reference.');
        }
    }

    private function validatePattern(mixed $pattern): void
    {
        if (! is_string($pattern) || $pattern === '' || str_starts_with($pattern, '/')) {
            throw new InvalidArgumentException('The repository path pattern is invalid.');
        }

        if (str_contains($pattern, "\0") || preg_match('/[\r\n]/', $pattern) === 1) {
            throw new InvalidArgumentException('The repository path pattern is invalid.');
        }

        if (preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~D', $pattern) === 1) {
            throw new InvalidArgumentException('The repository path pattern is invalid.');
        }

        if (preg_match('/[^A-Za-z0-9_@.+,=:\/\-*?]/', $pattern) === 1 || str_contains($pattern, '***')) {
            throw new InvalidArgumentException('The repository path pattern contains an unsupported character.');
        }
    }

    /** @return array<string, array{mode: string, type: string, object: string}> */
    private function treeEntries(string $commit): array
    {
        $output = $this->run(['ls-tree', '-r', '-z', '--full-tree', $commit]);
        $entries = [];

        foreach (explode("\0", $output) as $record) {
            if ($record === '') {
                continue;
            }

            $matches = [];

            if (preg_match('/\A([0-7]{6}) (blob|tree|commit) ([0-9a-f]{40})\t(.+)\z/Ds', $record, $matches) !== 1) {
                throw new InvalidArgumentException('Git returned an invalid tree entry.');
            }

            $entries[$matches[4]] = ['mode' => $matches[1], 'type' => $matches[2], 'object' => $matches[3]];
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    private function matches(string $pattern, string $path): bool
    {
        $quoted = preg_quote($pattern, '~');
        $quoted = str_replace('\\*\\*', '.*', $quoted);
        $quoted = str_replace('\\*', '[^/]*', $quoted);
        $quoted = str_replace('\\?', '[^/]', $quoted);

        return preg_match("~\\A{$quoted}\\z~D", $path) === 1;
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): string
    {
        $result = Process::path($this->path)->run(['git', ...$arguments]);

        if ($result->failed()) {
            throw new InvalidArgumentException('The Git command failed.');
        }

        return $result->output();
    }
}
