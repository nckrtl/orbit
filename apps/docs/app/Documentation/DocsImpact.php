<?php

declare(strict_types=1);

namespace App\Documentation;

use Illuminate\Support\Str;
use RuntimeException;

final readonly class DocsImpact
{
    private const array CLI_FAMILIES = [
        'activity', 'analytics', 'app', 'cluster', 'database', 'dns', 'doctor', 'env', 'extension', 'firewall', 'fleet',
        'gateway', 'github', 'instance', 'internal', 'metrics', 'node', 'process', 'profile', 'project', 'proxycli',
        'realtime', 'route', 'schedule', 'self-update', 'tasks', 'tool', 'workspace',
    ];

    private const array CLI_FAMILY_OWNERS = [
        'internal' => 'docs/reference/tasks.md',
    ];

    private const array NON_CLI_COMMAND_OWNERS = [
        'project-documents:cleanup:work' => 'docs/reference/project-documents.md',
        'project-documents:probes:reconcile' => 'docs/reference/project-documents.md',
        'project-documents:probes:repair' => 'docs/reference/project-documents.md',
        'tasks:tick' => 'docs/reference/tasks.md',
        'annotations:dispatch' => 'docs/reference/agent-annotation.md',
        'orbit:activity-finalize-interrupted' => 'docs/cli/activity.mdx',
        'orbit:deploy-development-defaults' => 'docs/reference/deployments.md',
        'orbit:desired-fleet-state' => 'docs/reference/self-update.md',
        'orbit:agent-view' => 'docs/reference/node-agent.md',
        'orbit:caddy-build' => 'docs/reference/gateway-trust.md',
        'orbit:tasks:jev-report' => 'docs/reference/tasks.md',
        'orbit:runtime-hibernator' => 'docs/reference/gateway-recovery.md',
        'tasks:render-prompt' => 'docs/reference/tasks.md',
        'orbit:private-dns-serve' => 'docs/reference/gateway-trust.md',
        'orbit:gateway-web' => 'docs/reference/gateway-trust.md',
        'orbit:agent-view-publish' => 'docs/reference/node-agent.md',
        'orbit:activity-redact' => 'docs/cli/activity.mdx',
        'orbit:bootstrap' => 'docs/reference/gateway-trust.md',
        'orbit:node-dns-repair' => 'docs/reference/node-agent.md',
        'orbit:node-provision' => 'docs/reference/node-agent.md',
        'tasks:collect-t3-metrics' => 'docs/reference/tasks.md',
        'problems:collect' => 'docs/reference/tasks.md',
        'problems:file' => 'docs/reference/tasks.md',
        'tasks:archive-threads' => 'docs/reference/tasks.md',
        'orbit:node-retarget' => 'docs/reference/node-agent.md',
        'queue:work task-vms --queue=task-vms --stop-when-empty --max-time=50 --timeout=1500' => 'docs/reference/compute-drivers.md',
    ];

    /** @param list<string>|null $ratchetPages */
    public function __construct(private string $root, private ?array $ratchetPages = null) {}

    /**
     * @param  list<string>  $plannedPaths
     * @return array{
     *   base: ?string,
     *   paths: list<string>,
     *   impacted_pages: list<array{page: string, reasons: list<string>}>,
     *   surfaces: list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>,
     *   surfaces_handled_by_generator: list<array{generator: string, paths: list<string>, status: string, current: bool}>,
     *   errors: list<string>,
     *   verdict: string
     * }
     */
    public function report(?string $base, array $plannedPaths): array
    {
        $paths = $plannedPaths;
        if ($base !== null) {
            $paths = [...$paths, ...$this->git(['diff', '--name-only', '--diff-filter=ACDMRTUXB', $base]), ...$this->git(['ls-files', '--others', '--exclude-standard'])];
        }
        $paths = $this->expandDirectories($paths);
        $paths = array_values(array_unique(array_map($this->normalize(...), $paths)));
        sort($paths);
        $impacts = [];
        $surfaces = [];
        $errors = [];
        $generators = [];
        $pages = $this->coveringPages();
        $operations = $this->apiOperations($base);

        foreach ($paths as $path) {
            if ($path === '' || $path === 'docs') {
                continue;
            }
            if ($path === 'apps/gateway/resources/mcp/tools.json') {
                $this->extractMcpManifest($path, $base, $surfaces, $impacts, $generators);
            }
            if ($this->extractGeneratedArtifact($path, $surfaces, $impacts, $generators) || str_starts_with($path, 'docs/')) {
                continue;
            }
            foreach ($pages as $page => $patterns) {
                foreach ($patterns as $pattern) {
                    if ($this->matches($path, $pattern)) {
                        $this->addSurface($surfaces, $impacts, $path, 'covered_path', $page, "covers: {$pattern} matches {$path}");
                    }
                }
            }
            if (preg_match('#(?:^|/)tests?/#', $path) === 1) {
                continue;
            }

            $source = is_file($this->root.'/'.$path) ? $this->read($path) : null;
            $baseSource = $base === null ? null : $this->gitAllowMissing(['show', $base.':'.$path]);
            if ($source === null && $baseSource === null) {
                $this->extractPlannedPath($path, $pages, $surfaces, $impacts, $errors);

                continue;
            }

            $this->extractCliRegistration($path, $surfaces, $impacts, $generators, $errors);
            $currentSurfaces = [];
            $currentImpacts = [];
            $currentGenerators = [];
            $currentErrors = [];
            if ($source !== null) {
                $this->extractSourceSurfaces($path, $source, $operations, $pages, $base, $currentSurfaces, $currentImpacts, $currentGenerators, $currentErrors);
            }
            $baseSurfaces = [];
            $baseImpacts = [];
            $baseGenerators = [];
            $baseErrors = [];
            if ($baseSource !== null) {
                $this->extractSourceSurfaces($path, $baseSource, $operations, $pages, $base, $baseSurfaces, $baseImpacts, $baseGenerators, $baseErrors);
            }

            foreach ([...$currentGenerators, ...$baseGenerators] as $generator => $entry) {
                foreach ($entry['paths'] as $generatorPath) {
                    $this->generator($generators, $generator, $generatorPath);
                }
            }
            $unchangedSnapshot = $source !== null && $baseSource !== null && rtrim($source, "\r\n") === rtrim($baseSource, "\r\n");
            $this->mergeChangedSourceSnapshots($path, $currentSurfaces, $baseSurfaces, $currentErrors, $baseErrors, $surfaces, $impacts, $errors, $unchangedSnapshot);
        }

        $handled = [];
        foreach ($generators as $name => $entry) {
            $status = $this->checkGenerator($name, $base);
            $pathsForGenerator = array_values(array_unique($entry['paths']));
            sort($pathsForGenerator);
            $handled[] = ['generator' => $name, 'paths' => $pathsForGenerator, 'status' => $status, 'current' => $status === 'passed'];
            foreach ($surfaces as &$surface) {
                if (in_array($surface['path'], $pathsForGenerator, true) && in_array($name, $entry['names'], true)) {
                    $surface['generator_status'] = $status;
                    if ($status === 'passed' && $surface['kind'] === 'generated_artifact') {
                        $this->removeReason($impacts, $surface['owner'], $surface['reason']);
                    }
                    $generatedApiPage = $name === 'bin/docs-openapi'
                        && in_array($surface['kind'], ['api_operation', 'error_code'], true)
                        && preg_match('/^(?:GET|POST|PUT|PATCH|DELETE) \/api\/v1\//', $surface['owner']) === 1;
                    $generatedMcpPage = $name === 'bin/mcp-tools'
                        && $surface['kind'] === 'mcp_tool'
                        && $this->isApiPath($surface['path']);
                    if ($status === 'passed' && ($generatedApiPage || $generatedMcpPage)) {
                        $this->removeReason($impacts, $surface['owner'], $surface['reason']);
                    }
                }
            }
            unset($surface);
            if ($status !== 'passed') {
                foreach ($pathsForGenerator as $generatorPath) {
                    $reason = "{$name} generator status is {$status}";
                    $this->addSurface($surfaces, $impacts, $generatorPath, 'generated_surface', $this->generatedOwner($name), $reason, $status);
                }
            }
        }
        $generatorStatuses = array_column($handled, 'status', 'generator');
        if (($generatorStatuses['bin/docs-openapi'] ?? null) === 'passed' && ($generatorStatuses['bin/api-fixtures'] ?? null) === 'passed' && ($generatorStatuses['bin/mcp-tools'] ?? null) === 'passed') {
            $handledApiPaths = array_fill_keys(array_intersect(
                $generators['bin/docs-openapi']['paths'] ?? [],
                $generators['bin/api-fixtures']['paths'] ?? [],
                $generators['bin/mcp-tools']['paths'] ?? [],
            ), true);
            foreach ($surfaces as $surface) {
                if (isset($handledApiPaths[$surface['path']]) && in_array($surface['kind'], ['api_operation', 'error_code'], true) && $surface['owner'] === 'unowned') {
                    $this->removeReason($impacts, $surface['owner'], $surface['reason']);
                }
            }
            $surfaces = array_values(array_filter($surfaces, static fn (array $surface): bool => ! (isset($handledApiPaths[$surface['path']]) && in_array($surface['kind'], ['api_operation', 'error_code'], true) && $surface['owner'] === 'unowned')));
            $errors = array_values(array_filter($errors, static function (string $error) use ($handledApiPaths): bool {
                $diagnostic = str_starts_with($error, 'Removed from current source: ')
                    ? substr($error, strlen('Removed from current source: '))
                    : $error;

                return array_all(array_keys($handledApiPaths), static fn (string $path): bool => ! (
                    str_starts_with($diagnostic, "Unable to map API source [{$path}]")
                    || (str_starts_with($diagnostic, 'Unowned error identifier [') && str_contains($diagnostic, " in {$path}:"))
                ));
            }));
        }
        usort($surfaces, static fn (array $left, array $right): int => [$left['path'], $left['kind'], $left['owner'], $left['reason']] <=> [$right['path'], $right['kind'], $right['owner'], $right['reason']]);
        ksort($impacts);
        foreach ($impacts as &$impact) {
            $impact['reasons'] = array_values(array_unique($impact['reasons']));
            sort($impact['reasons']);
        }
        unset($impact);
        sort($errors);
        usort($handled, static fn (array $a, array $b): int => $a['generator'] <=> $b['generator']);

        return [
            'base' => $base,
            'paths' => $paths,
            'impacted_pages' => array_map(static fn (string $page, array $impact): array => ['page' => $page, 'reasons' => $impact['reasons']], array_keys($impacts), $impacts),
            'surfaces' => $surfaces,
            'surfaces_handled_by_generator' => $handled,
            'errors' => array_values(array_unique($errors)),
            'verdict' => $impacts === [] && $errors === [] && array_filter($handled, static fn (array $generator): bool => $generator['status'] !== 'passed') === [] ? 'no_docs_change' : 'docs_required',
        ];
    }

    /**
     * @return array{
     *   report: array{base: ?string, paths: list<string>, impacted_pages: list<array{page: string, reasons: list<string>}>, surfaces: list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>, surfaces_handled_by_generator: list<array{generator: string, paths: list<string>, status: string, current: bool}>, errors: list<string>, verdict: string},
     *   missing_pages: list<string>,
     *   exceptions: array<string, string>,
     *   failures: list<string>,
     *   passed: bool
     * }
     */
    public function gate(string $base): array
    {
        $report = $this->report($base, []);
        $changed = array_fill_keys($report['paths'], true);
        $exceptions = [];
        foreach ($this->unaffectedPageNotes() as $note) {
            if (preg_match('/^([^:#]+):\\s*(\\S.*)$/', trim($note), $match) === 1) {
                $exceptions[$match[1]] = $match[2];
            }
        }
        $missing = [];
        foreach ($report['impacted_pages'] as $impact) {
            $page = $impact['page'];
            if (! str_starts_with($page, 'docs/') || isset($changed[$page]) || isset($exceptions[$page])) {
                continue;
            }
            $missing[] = $page;
        }
        sort($missing);
        $failures = $report['errors'];
        foreach ($report['surfaces_handled_by_generator'] as $generator) {
            if ($generator['status'] !== 'passed') {
                $failures[] = "{$generator['generator']} generator status is {$generator['status']}.";
            }
        }
        $failures = array_values(array_unique($failures));
        sort($failures);

        return [
            'report' => $report,
            'missing_pages' => $missing,
            'exceptions' => $exceptions,
            'failures' => $failures,
            'passed' => $missing === [] && $failures === [],
        ];
    }

    /** @return list<array{page: string, pattern: string, message: string}> */
    public function coverageFindings(): array
    {
        $tracked = $this->git(['ls-files']);
        $trackedSet = [];
        foreach ($tracked as $trackedPath) {
            $trackedSet[$trackedPath] = true;
            $directory = dirname($trackedPath);
            while ($directory !== '.' && $directory !== '') {
                $trackedSet[$directory] = true;
                $directory = dirname($directory);
            }
        }
        $documents = glob($this->root.'/docs/{reference,cli,solutions}/*.{md,mdx}', GLOB_BRACE) ?: [];
        $findings = [];
        $historicalRatchet = $this->ratchetPages ?? $this->committedRatchetPages();
        $currentRatchet = $this->ratchetPages ?? $this->currentRatchetPages();
        foreach (array_diff($historicalRatchet, $currentRatchet) as $removedPage) {
            $findings[] = [
                'page' => 'apps/docs/config/docs-covers-ratchet.php',
                'pattern' => $removedPage,
                'message' => "The coverage ratchet cannot remove {$removedPage}.",
            ];
        }
        $allowed = array_values(array_unique([...$historicalRatchet, ...$currentRatchet]));
        foreach ($currentRatchet as $listedPage) {
            if (! is_file($this->root.'/'.$listedPage)) {
                $findings[] = ['page' => 'apps/docs/config/docs-covers-ratchet.php', 'pattern' => $listedPage, 'message' => "The coverage ratchet lists missing page {$listedPage}."];
            }
        }
        foreach ($documents as $file) {
            $page = 'docs/'.substr($file, strlen($this->root.'/docs/'));
            $content = $this->frontmatter($this->read($page));
            $has = preg_match('/^covers\s*:/m', $content) === 1;
            if (in_array($page, $allowed, true) && ! $has) {
                $findings[] = ['page' => $page, 'pattern' => 'covers:', 'message' => 'covers: cannot be removed from the committed coverage ratchet.'];
            }
            if (! $has) {
                continue;
            }
            preg_match('/^covers\s*:\s*\R((?:^[ \t]+.*\R?)*)/m', $content, $block);
            $patterns = [];
            foreach (preg_split('/\R/', trim($block[1] ?? '')) ?: [] as $line) {
                if (preg_match('/^\s*-\s*[\'"]?([^\'"]+?)[\'"]?\s*$/', $line, $match) !== 1) {
                    $findings[] = ['page' => $page, 'pattern' => trim($line), 'message' => 'covers: must be a list of non-empty strings.'];

                    continue;
                }
                $rawPattern = trim($match[1]);
                $pattern = $this->coveragePattern($rawPattern);
                if ($pattern === null) {
                    $findings[] = ['page' => $page, 'pattern' => $rawPattern, 'message' => 'covers: must be a safe repository-relative glob.'];
                } elseif (! $this->matchesAny($trackedSet, $pattern)) {
                    $findings[] = ['page' => $page, 'pattern' => $pattern, 'message' => 'covers: pattern does not match a tracked path.'];
                } else {
                    $patterns[] = $pattern;
                }
            }
            if ($patterns === []) {
                $findings[] = ['page' => $page, 'pattern' => 'covers:', 'message' => 'covers: must contain at least one valid glob.'];
            }
        }

        return $findings;
    }

    /**
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $currentSurfaces
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $baseSurfaces
     * @param  list<string>  $currentErrors
     * @param  list<string>  $baseErrors
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  list<string>  $errors
     */
    private function mergeChangedSourceSnapshots(string $path, array $currentSurfaces, array $baseSurfaces, array $currentErrors, array $baseErrors, array &$surfaces, array &$impacts, array &$errors, bool $unchangedSnapshot = false): void
    {
        $currentByKey = [];
        foreach ($currentSurfaces as $surface) {
            $currentByKey[$this->surfaceKey($surface)] = $surface;
        }
        $baseByKey = [];
        foreach ($baseSurfaces as $surface) {
            $baseByKey[$this->surfaceKey($surface)] = $surface;
        }
        foreach ($unchangedSnapshot ? $currentByKey : array_diff_key($currentByKey, $baseByKey) as $surface) {
            $this->addSurface($surfaces, $impacts, $path, $surface['kind'], $surface['owner'], $surface['reason']);
        }
        if (! $unchangedSnapshot) {
            foreach (array_diff_key($baseByKey, $currentByKey) as $surface) {
                $this->addSurface($surfaces, $impacts, $path, $surface['kind'], $surface['owner'], 'Removed from current source: '.$surface['reason']);
            }
        }

        $currentErrorKeys = array_fill_keys(array_map($this->errorIdentity(...), $currentErrors), true);
        $baseErrorKeys = array_fill_keys(array_map($this->errorIdentity(...), $baseErrors), true);
        $migrationKeysChanged = array_keys(array_filter($currentByKey, static fn (array $surface): bool => $surface['kind'] === 'migration' && $surface['owner'] === 'unowned'))
            !== array_keys(array_filter($baseByKey, static fn (array $surface): bool => $surface['kind'] === 'migration' && $surface['owner'] === 'unowned'));
        foreach ($currentErrors as $error) {
            if (! isset($baseErrorKeys[$this->errorIdentity($error)]) || (str_starts_with($error, 'Unowned migration surface') && $migrationKeysChanged)) {
                $errors[] = $error;
            }
        }
        foreach ($baseErrors as $error) {
            if (! isset($currentErrorKeys[$this->errorIdentity($error)])) {
                $errors[] = 'Removed from current source: '.$error;
            }
        }
    }

    private function errorIdentity(string $error): string
    {
        return preg_replace('/:\\d+(?=\\.?$)/', '', $error) ?? $error;
    }

    /**
     * @param  list<array{key: string, path: string, operations: array<string, mixed>}>  $operations
     * @param  array<string, list<string>>  $pages
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  array<string, array{paths: list<string>, names: list<string>}>  $generators
     * @param  list<string>  $errors
     */
    private function extractSourceSurfaces(string $path, string $source, array $operations, array $pages, ?string $base, array &$surfaces, array &$impacts, array &$generators, array &$errors): void
    {
        $this->extractEnvironmentKeys($path, $source, $surfaces, $impacts);
        $this->extractCliSurface($path, $source, $surfaces, $impacts, $generators, $errors);
        $this->extractApiSurface($path, $source, $operations, $base, $surfaces, $impacts, $generators, $errors);
        $this->extractDoctorCodes($path, $source, $surfaces, $impacts);
        $this->extractErrorCodes($path, $source, $operations, $pages, $base, $surfaces, $impacts, $errors);
        $this->extractMigration($path, $source, $pages, $surfaces, $impacts, $errors);
        $this->extractRegisteredCommands($path, $source, $pages, $surfaces, $impacts, $errors);
        $this->extractScheduleDomain($path, $source, $surfaces, $impacts);
        $this->extractMcpSurface($path, $source, $surfaces, $impacts, $generators);
    }

    /** @param array{path: string, kind: string, owner: string, reason: string, generator_status: ?string} $surface */
    private function surfaceKey(array $surface): string
    {
        $location = '/'.preg_quote($surface['path'], '/').':\\d+\\b/';
        $reason = preg_replace($location, $surface['path'], $surface['reason']) ?? $surface['reason'];

        return implode('|', [$surface['path'], $surface['kind'], $surface['owner'], $reason]);
    }

    private function commandName(string $signature): string
    {
        return preg_match('/^\\s*([a-z][a-z0-9-]*(?::[a-z0-9-]+)*)/i', $signature, $match) === 1 ? strtolower($match[1]) : '';
    }

    private function commandFamily(string $signature): string
    {
        if (preg_match('/^\s*([a-z][a-z0-9-]*(?::[a-z0-9-]+)*)/i', $signature, $match) !== 1) {
            return '';
        }

        return strtolower(explode(':', $match[1], 2)[0]);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function expandDirectories(array $paths): array
    {
        $expanded = [];
        foreach ($paths as $input) {
            $path = $this->normalize($input);
            $absolute = $this->root.'/'.$path;
            if (! is_dir($absolute)) {
                $expanded[] = $path;

                continue;
            }
            $children = $this->git(['ls-files', '--', $path]);
            foreach ($this->filesUnder($absolute) as $file) {
                if ($file->isFile()) {
                    $children[] = $path.'/'.substr($file->getPathname(), strlen($absolute) + 1);
                }
            }
            $children = array_values(array_unique($children));
            if ($children === []) {
                $expanded[] = $path;
            } else {
                array_push($expanded, ...$children);
            }
        }

        return $expanded;
    }

    private function sourceForPath(string $path, ?string $base): ?string
    {
        if (is_file($this->root.'/'.$path)) {
            return $this->read($path);
        }
        if ($base !== null) {
            return $this->gitAllowMissing(['show', $base.':'.$path]);
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $pages
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  list<string>  $errors
     */
    private function extractPlannedPath(string $path, array $pages, array &$surfaces, array &$impacts, array &$errors): void
    {
        if (preg_match('#(?:^|/)Domain/Doctor(?:/|$)#', $path) === 1 || preg_match('#(?:^|/)config/doctor(?:\.php)?$#i', $path) === 1) {
            $this->addSurface($surfaces, $impacts, $path, 'doctor_issue_code', 'docs/cli/doctor.mdx', "Planned Doctor surface {$path}");

            return;
        }
        foreach ($pages as $page => $patterns) {
            foreach ($patterns as $pattern) {
                if ($this->matches($path, $pattern)) {
                    return;
                }
            }
        }
        $errors[] = "Unable to resolve planned surface [{$path}]; provide an existing path or a covers: owner.";
        $this->addSurface($surfaces, $impacts, $path, 'planned_surface', 'unowned', "Unresolved planned surface {$path}");
    }

    /**
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     */
    private function addSurface(array &$surfaces, array &$impacts, string $path, string $kind, string $owner, string $reason, ?string $generatorStatus = null): void
    {
        $surfaces[] = ['path' => $path, 'kind' => $kind, 'owner' => $owner, 'reason' => $reason, 'generator_status' => $generatorStatus];
        $this->addReason($impacts, $owner, $reason);
    }

    /** @param array<string, array{reasons: list<string>}> $impacts */
    private function removeReason(array &$impacts, string $page, string $reason): void
    {
        if (! isset($impacts[$page])) {
            return;
        }
        $impacts[$page]['reasons'] = array_values(array_filter($impacts[$page]['reasons'], static fn (string $item): bool => $item !== $reason));
        if ($impacts[$page]['reasons'] === []) {
            unset($impacts[$page]);
        }
    }

    /** @param array<string, array{reasons: list<string>}> $impacts */
    private function addReason(array &$impacts, string $page, string $reason): void
    {
        $impacts[$page] ??= ['reasons' => []];
        $impacts[$page]['reasons'][] = $reason;
    }

    /** @param array<string, array{paths: list<string>, names: list<string>}> $generators */
    private function generator(array &$generators, string $name, string $path): void
    {
        $generators[$name] ??= ['paths' => [], 'names' => []];
        $generators[$name]['paths'][] = $path;
        $generators[$name]['names'][] = $name;
    }

    /**
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  array<string, array{paths: list<string>, names: list<string>}>  $generators
     */
    private function extractGeneratedArtifact(string $path, array &$surfaces, array &$impacts, array &$generators): bool
    {
        $generator = match ($path) {
            'docs/openapi.json', 'docs/docs.json' => 'bin/docs-openapi',
            'apps/gateway/resources/mcp/tools.json' => 'bin/mcp-tools',
            default => null,
        };
        if ($generator === null) {
            return false;
        }
        $this->generator($generators, $generator, $path);
        $this->addSurface($surfaces, $impacts, $path, 'generated_artifact', $path, "Generated output {$path} changed; run {$generator}.");

        return true;
    }

    /**
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     */
    private function extractEnvironmentKeys(string $path, string $source, array &$surfaces, array &$impacts): void
    {
        $keys = [];
        $configIndent = null;
        foreach (preg_split('/\R/', $source) ?: [] as $index => $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $match) === 1 && str_contains($path, '.env')) {
                $keys[] = [$match[1], $index + 1];
            }
            if (preg_match('#(?:^|/)config/#', $path) === 1 && preg_match('/^([ \t]*)[\'"]([A-Za-z][A-Za-z0-9_.-]*)[\'"]\s*=>/', $line, $match) === 1) {
                $indent = strlen($match[1]);
                $configIndent ??= $indent;
                if ($indent === $configIndent) {
                    $keys[] = [$match[2], $index + 1];
                }
            }
        }
        foreach (preg_split('/\R/', $source) ?: [] as $index => $line) {
            if (preg_match('/^(?:\/\/|\*|#)/', ltrim($line)) === 1) {
                continue;
            }
            if (preg_match_all('/env\s*\(\s*(?:key\s*:\s*)?[\'"]([A-Z][A-Z0-9_]*)[\'"]/', $line, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $keys[] = [$key, $index + 1];
                }
            }
        }
        if ($keys === [] && str_contains($path, '.env')) {
            $keys[] = ['configuration', 1];
        }
        foreach ($keys as [$key, $line]) {
            $this->addSurface($surfaces, $impacts, $path, 'environment_key', 'docs/reference/environment-variables.md', "{$key} in {$path}:{$line}");
        }
    }

    /**
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  array<string, array{paths: list<string>, names: list<string>}>  $generators
     * @param  list<string>  $errors
     */
    private function extractCliRegistration(string $path, array &$surfaces, array &$impacts, array &$generators, array &$errors): void
    {
        if ($path !== 'apps/cli/config/commands.php') {
            return;
        }
        $this->generator($generators, 'bin/cli-contract', $path);
        $this->generator($generators, 'bin/docs-openapi', $path);
        $families = [];
        foreach ($this->git(['ls-files', 'apps/cli/app/Commands']) as $commandPath) {
            $contents = $this->read($commandPath);
            if (preg_match('/protected\s+\$signature\s*=\s*[\'"]([^\'"]+)/', $contents, $signature) !== 1) {
                continue;
            }
            $family = $this->commandFamily($signature[1]);
            if (! in_array($family, self::CLI_FAMILIES, true)) {
                $errors[] = "Unknown CLI command family [{$family}] registered by {$commandPath}.";

                continue;
            }
            $families[$family] = true;
        }
        foreach (array_keys($families) as $family) {
            $page = self::CLI_FAMILY_OWNERS[$family] ?? 'docs/cli/'.$family.'.mdx';
            $this->addSurface($surfaces, $impacts, $path, 'cli_registration', $page, "CLI family {$family} is registered through {$path}");
        }
    }

    /**
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{paths: list<string>, names: list<string>}>  $generators
     * @param  list<string>  $errors
     */
    private function extractCliSurface(string $path, string $source, array &$surfaces, array &$impacts, array &$generators, array &$errors): void
    {
        if (! str_starts_with($path, 'apps/cli/app/Commands/')) {
            return;
        }
        $this->generator($generators, 'bin/cli-contract', $path);
        $this->generator($generators, 'bin/docs-openapi', $path);
        if (preg_match('/protected\s+\$signature\s*=\s*[\'"]([^\'"]+)/', $source, $match) !== 1) {
            return;
        }
        $command = trim(preg_replace('/\s+/', ' ', $match[1]) ?? $match[1]);
        $family = $this->commandFamily($command);
        if (! in_array($family, self::CLI_FAMILIES, true)) {
            $errors[] = "Unknown CLI command family [{$family}] in {$path}.";
            $this->addSurface($surfaces, $impacts, $path, 'cli_signature', 'unowned', "unknown CLI family {$family}");

            return;
        }
        $page = self::CLI_FAMILY_OWNERS[$family] ?? 'docs/cli/'.$family.'.mdx';
        if (! is_file($this->root.'/'.$page)) {
            $errors[] = "CLI family [{$family}] has no documentation owner at {$page}.";
            $this->addSurface($surfaces, $impacts, $path, 'cli_signature', 'unowned', "missing CLI owner {$page} for {$command}");

            return;
        }
        preg_match('/protected\s+\$description\s*=\s*[\'"]([^\'"]*)/', $source, $description);
        $this->addSurface($surfaces, $impacts, $path, 'cli_signature', $page, "CLI command {$command} in {$path}; description ".trim($description[1] ?? ''));
        if ($family === 'env' || str_contains(strtolower($source), 'instance .env') || str_contains($source, "'.env'") || preg_match('/(?:Update|Import|Synchronize)(?:App)?InstanceEnvironmentRequest/', $source) === 1) {
            $this->addSurface($surfaces, $impacts, $path, 'instance_environment_command', 'docs/cli/env.mdx', "CLI command reads or changes Instance .env in {$path}");
        }
    }

    /**
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  list<array{key: string, path: string, operations: array<string, mixed>}>  $operations
     * @param  array<string, array{paths: list<string>, names: list<string>}>  $generators
     * @param  list<string>  $errors
     */
    private function extractApiSurface(string $path, string $source, array $operations, ?string $base, array &$surfaces, array &$impacts, array &$generators, array &$errors): void
    {
        if (! $this->isApiPath($path)) {
            return;
        }
        foreach (['bin/docs-openapi', 'bin/api-fixtures', 'bin/mcp-tools'] as $generator) {
            $this->generator($generators, $generator, $path);
        }
        $keys = $this->matchingApiOperations($path, $source, $operations, $base);
        if ($keys === []) {
            $errors[] = "Unable to map API source [{$path}] to an OpenAPI operation or schema owner.";
            $this->addSurface($surfaces, $impacts, $path, 'api_operation', 'unowned', "API operation owner not found for {$path}");

            return;
        }
        foreach ($keys as $key) {
            $this->addSurface($surfaces, $impacts, $path, 'api_operation', $key, "OpenAPI operation {$key} may change via {$path}");
            if ($this->mcpManifestHasOperation($key)) {
                $this->addSurface($surfaces, $impacts, $path, 'mcp_tool', 'docs/reference/mcp.mdx', "MCP tool for {$key} may change via {$path}");
            }
        }
        if (str_starts_with($path, 'apps/gateway/app/Http/Mcp/')) {
            $this->addSurface($surfaces, $impacts, $path, 'mcp_tool', 'docs/reference/mcp.mdx', "MCP tool definition changed in {$path}");
        }
    }

    /**
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     */
    private function extractDoctorCodes(string $path, string $source, array &$surfaces, array &$impacts): void
    {
        if (! str_contains($path, 'DoctorIssueCode') || ! str_contains($source, 'implements DoctorIssueCode')) {
            return;
        }
        if (preg_match_all('/case\s+[A-Za-z][A-Za-z0-9_]*\s*=\s*[\'"]([a-z][a-z0-9]*(?:[._-][a-z0-9]+)+)[\'"]/', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return;
        }
        $family = preg_match('/function\s+family\s*\(\)\s*:\s*DoctorFamily\s*\{[^}]*DoctorFamily::([A-Za-z][A-Za-z0-9_]*)/', $source, $familyMatch) === 1
            ? $familyMatch[1]
            : 'unknown';
        foreach ($matches[1] as [$identifier, $offset]) {
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $this->addSurface($surfaces, $impacts, $path, 'doctor_issue_code', 'docs/cli/doctor.mdx', "Doctor {$family} issue code {$identifier} in {$path}:{$line}");
        }
    }

    /**
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  list<array{key: string, path: string, operations: array<string, mixed>}>  $operations
     * @param  array<string, list<string>>  $pages
     * @param  list<string>  $errors
     */
    private function extractErrorCodes(string $path, string $source, array $operations, array $pages, ?string $base, array &$surfaces, array &$impacts, array &$errors): void
    {
        $identifiers = [];
        $pattern = '/[\'"]?(?:errorCode|error_code|errorId|requiredCode|code)[\'"]?\s*(?:=>|[:=,])\s*[\'"]([a-z][a-z0-9]*(?:[._-][a-z0-9]+)+)[\'"]|renderGatewayFailure\s*\(\s*[\'"]([a-z][a-z0-9]*(?:[._-][a-z0-9]+)+)[\'"]/';
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $index => $wholeMatch) {
                $identifiers[] = $matches[1][$index][1] >= 0 ? $matches[1][$index] : $matches[2][$index];
            }
        }
        if (preg_match_all('/\\bconst\\s+(?:[A-Za-z_\\\\|?]+\\s+)?([A-Z][A-Z0-9_]*)\\s*=\\s*[\'"]([a-z][a-z0-9]*(?:[._-][a-z0-9]+)+)[\'"]/', $source, $constantMatches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($constantMatches[2] as $constantMatch) {
                $identifiers[] = $constantMatch;
            }
        }
        foreach ($identifiers as [$identifier, $offset]) {
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $owners = [];
            if (str_starts_with($path, 'apps/cli/')) {
                if (preg_match('/protected\s+\$signature\s*=\s*[\'"]([^\'"]+)/', $source, $signature) === 1) {
                    $family = $this->commandFamily($signature[1]);
                    $owners[] = self::CLI_FAMILY_OWNERS[$family] ?? 'docs/cli/'.$family.'.mdx';
                } elseif (preg_match('/^([a-z][a-z0-9]*)\./', $identifier, $familyMatch) === 1 && in_array($familyMatch[1], self::CLI_FAMILIES, true)) {
                    $family = $familyMatch[1];
                    $owners[] = self::CLI_FAMILY_OWNERS[$family] ?? 'docs/cli/'.$family.'.mdx';
                }
            } elseif ($this->isApiPath($path)) {
                $owners = [];
                foreach ($operations as $operation) {
                    if ($this->containsIdentifier($operation['operations'], $identifier)) {
                        $owners[] = $operation['key'];
                    }
                }
                if ($owners === []) {
                    $owners = $this->matchingApiOperations($path, $source, $operations, $base);
                }
            }
            if ($owners === []) {
                foreach ($pages as $page => $patterns) {
                    foreach ($patterns as $coveragePattern) {
                        if ($this->matches($path, $coveragePattern)) {
                            $owners[] = $page;
                            break;
                        }
                    }
                }
            }
            if ($owners === []) {
                $errors[] = "Unowned error identifier [{$identifier}] in {$path}:{$line}.";
                $this->addSurface($surfaces, $impacts, $path, 'error_code', 'unowned', "unowned error identifier {$identifier} at {$path}:{$line}");

                continue;
            }
            foreach ($owners as $owner) {
                $this->addSurface($surfaces, $impacts, $path, 'error_code', $owner, "error identifier {$identifier} at {$path}:{$line}");
            }
        }
    }

    /**
     * @param  array<string, list<string>>  $pages
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  list<string>  $errors
     */
    private function extractMigration(string $path, string $source, array $pages, array &$surfaces, array &$impacts, array &$errors): void
    {
        if (! str_contains($path, '/database/migrations/')) {
            return;
        }
        preg_match_all('/Schema::(create|table)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches, PREG_OFFSET_CAPTURE);
        $operations = [];
        foreach ($matches[1] as $index => [$operation, $offset]) {
            $table = $matches[2][$index][0];
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $operations[] = "{$operation} {$table} at {$path}:{$line}";
            $segmentStart = $matches[0][$index][1];
            $segmentLength = isset($matches[0][$index + 1]) ? $matches[0][$index + 1][1] - $segmentStart : null;
            $segment = substr($source, $segmentStart, $segmentLength);
            foreach ($this->blueprintOperations($segment) as [$blueprintOperation, $blueprintOffset]) {
                $operationLine = $line + substr_count(substr($segment, 0, $blueprintOffset), "\n");
                $operations[] = "{$table}.{$blueprintOperation} at {$path}:{$operationLine}";
            }
        }
        if ($operations === []) {
            $operations[] = "schema migration changed at {$path}";
        }
        $tableNames = [];
        foreach ($matches[2] as $tableMatch) {
            $tableNames[] = $tableMatch[0];
        }
        $owners = [];
        $modelResources = [];
        foreach ($this->git(['ls-files', 'apps/gateway/app/Models']) as $modelPath) {
            $modelSource = $this->read($modelPath);
            $modelName = pathinfo($modelPath, PATHINFO_FILENAME);
            $modelTableName = preg_match('/(?:protected|public)\s+\$table\s*=\s*[\'"]([^\'"]+)[\'"]/', $modelSource, $modelTable) === 1
                ? $modelTable[1]
                : Str::snake(Str::plural($modelName));
            if (! in_array($modelTableName, $tableNames, true)) {
                continue;
            }
            $modelResources[] = $modelPath;
            foreach ($pages as $page => $patterns) {
                foreach ($patterns as $pattern) {
                    if ($this->matches($modelPath, $pattern)) {
                        $owners[] = $page;
                    }
                }
            }
        }
        foreach ($pages as $page => $patterns) {
            foreach ($patterns as $pattern) {
                if ($this->matches($path, $pattern)) {
                    $owners[] = $page;
                }
            }
        }
        if ($owners === []) {
            $errors[] = "Unowned migration surface in {$path}; add a matching covers glob.";
            foreach ($operations as $operation) {
                $this->addSurface($surfaces, $impacts, $path, 'migration', 'unowned', $operation);
            }

            return;
        }
        foreach (array_unique($owners) as $owner) {
            foreach ($operations as $operation) {
                $resourceReason = $modelResources === [] ? '' : ' model resource '.implode(', ', $modelResources);
                $this->addSurface($surfaces, $impacts, $path, 'migration', $owner, $operation.$resourceReason);
            }
        }
    }

    /** @return list<array{0: string, 1: int}> */
    private function blueprintOperations(string $segment): array
    {
        preg_match_all('/\$table->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $segment, $calls, PREG_OFFSET_CAPTURE);
        $operations = [];
        $chainedCalls = [];
        foreach ($calls[1] as $index => [$method, $methodOffset]) {
            $callOffset = $calls[0][$index][1];
            if (isset($chainedCalls[$callOffset])) {
                continue;
            }
            $opening = strpos($segment, '(', $callOffset);
            $closing = $opening === false ? null : $this->matchingParenthesis($segment, $opening);
            if ($closing === null) {
                continue;
            }
            $arguments = substr($segment, $opening + 1, $closing - $opening - 1);
            $operations[] = [$this->blueprintCall($method, $arguments), $callOffset];
            $cursor = $closing + 1;
            while (preg_match('/\G\s*->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $segment, $chain, PREG_OFFSET_CAPTURE, $cursor) === 1) {
                $chainOffset = $chain[0][1];
                $chainOpening = $chainOffset + strpos($chain[0][0], '(');
                $chainClosing = $this->matchingParenthesis($segment, $chainOpening);
                if ($chainClosing === null) {
                    break;
                }
                $chainArguments = substr($segment, $chainOpening + 1, $chainClosing - $chainOpening - 1);
                $operations[] = [$this->blueprintCall($chain[1][0], $chainArguments), $chainOffset];
                $chainedCalls[$chainOffset] = true;
                $cursor = $chainClosing + 1;
            }
        }

        return $operations;
    }

    private function blueprintCall(string $method, string $arguments): string
    {
        preg_match_all('/[\'"]([^\'"]*)[\'"]/', $arguments, $literals);
        $summary = $literals[1] !== []
            ? implode(',', $literals[1])
            : trim(preg_replace('/\s+/', ' ', $arguments) ?? $arguments);

        return $method.'('.$summary.')';
    }

    private function matchingParenthesis(string $source, int $opening): ?int
    {
        $depth = 0;
        $quote = null;
        for ($index = $opening, $length = strlen($source); $index < $length; $index++) {
            $character = $source[$index];
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($character === '\'' || $character === '"') {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $pages
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  list<string>  $errors
     */
    private function extractRegisteredCommands(string $path, string $source, array $pages, array &$surfaces, array &$impacts, array &$errors): void
    {
        if (str_starts_with($path, 'apps/gateway/app/Console/Commands/') && preg_match('/protected\\s+\\$signature\\s*=\\s*[\'\"]([^\'\"]+)/', $source, $signature) === 1) {
            $command = trim(preg_replace('/\\s+/', ' ', $signature[1]) ?? $signature[1]);
            $page = self::NON_CLI_COMMAND_OWNERS[$this->commandName($command)] ?? null;
            if ($page === null) {
                foreach ($pages as $candidate => $patterns) {
                    if (array_filter($patterns, fn (string $pattern): bool => $this->matches($path, $pattern)) !== []) {
                        $page = $candidate;
                        break;
                    }
                }
            }
            if ($page === null || ! is_file($this->root.'/'.$page)) {
                $errors[] = "Unowned Gateway console command [{$this->commandName($command)}] in {$path}.";
                $this->addSurface($surfaces, $impacts, $path, 'registered_command', 'unowned', "unowned Gateway command {$command}");
            } else {
                $this->addSurface($surfaces, $impacts, $path, 'registered_command', $page, "Gateway console command {$command} in {$path}");
            }
        }
        if (preg_match_all('/(?:Schedule::command|Artisan::command|\$schedule->command)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return;
        }
        foreach ($matches[1] as [$command, $offset]) {
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $statement = substr($source, $offset, (strpos($source, ';', $offset) ?: strlen($source)) - $offset);
            preg_match('/->[^;]+/', $statement, $scheduleExpression);
            $expression = $scheduleExpression[0] ?? '';
            $page = self::NON_CLI_COMMAND_OWNERS[$command] ?? null;
            if ($page === null && str_contains($command, ':')) {
                $family = explode(':', $command, 2)[0];
                if (in_array($family, self::CLI_FAMILIES, true)) {
                    $page = self::CLI_FAMILY_OWNERS[$family] ?? 'docs/cli/'.$family.'.mdx';
                }
            }
            if ($page === null) {
                $errors[] = "Unowned scheduled or Artisan command [{$command}] in {$path}:{$line}.";
                $this->addSurface($surfaces, $impacts, $path, 'registered_command', 'unowned', "unowned command {$command} at {$path}:{$line}");

                continue;
            }
            if (! is_file($this->root.'/'.$page)) {
                $errors[] = "Registered command [{$command}] has no documentation owner at {$page}.";
                $this->addSurface($surfaces, $impacts, $path, 'registered_command', 'unowned', "missing command owner {$page} for {$command}");

                continue;
            }
            $this->addSurface($surfaces, $impacts, $path, 'registered_command', $page, "registered command {$command} at {$path}:{$line}".($expression === '' ? '' : " expression {$expression}"));
            if ($this->isScheduleRegistrationPath($path)) {
                $this->addSurface($surfaces, $impacts, $path, 'schedule', 'docs/reference/schedules.md', "schedule timing or registration changed at {$path}:{$line}".($expression === '' ? '' : " expression {$expression}"));
            }
        }
    }

    /**
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     */
    private function extractScheduleDomain(string $path, string $source, array &$surfaces, array &$impacts): void
    {
        if (! $this->isScheduleRegistrationPath($path)) {
            return;
        }
        if (preg_match_all('/(?:Schedule::(?:command|call|job)|\$schedule->(?:command|call|job)|->withSchedule)\s*\([^;]*/', $source, $matches) === 0) {
            return;
        }
        foreach (array_unique(array_map(static fn (string $registration): string => trim(preg_replace('/\s+/', ' ', $registration) ?? $registration), $matches[0])) as $registration) {
            $this->addSurface($surfaces, $impacts, $path, 'schedule', 'docs/reference/schedules.md', "Schedule registration {$registration} in {$path}");
        }
    }

    private function isScheduleRegistrationPath(string $path): bool
    {
        return preg_match('#(?:^|/)routes/console\.php$#', $path) === 1
            || preg_match('#(?:^|/)bootstrap/app\.php$#', $path) === 1
            || preg_match('#(?:^|/)app/Console/Kernel\.php$#', $path) === 1
            || preg_match('#(?:^|/)app/Domain/Tasks/TaskSchedule\.php$#', $path) === 1;
    }

    /**
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  array<string, array{paths: list<string>, names: list<string>}>  $generators
     */
    private function extractMcpManifest(string $path, ?string $base, array &$surfaces, array &$impacts, array &$generators): void
    {
        $this->generator($generators, 'bin/mcp-tools', $path);
        $current = json_decode($this->read($path), true);
        $oldContents = $base === null ? null : $this->gitAllowMissing(['show', $base.':'.$path]);
        $previous = $oldContents === null ? null : json_decode($oldContents, true);
        $currentTools = $this->mcpToolsByName($current);
        $previousTools = $this->mcpToolsByName($previous);
        $names = array_values(array_unique([...array_keys($currentTools), ...array_keys($previousTools)]));
        sort($names);
        foreach ($names as $name) {
            $now = $currentTools[$name] ?? null;
            $before = $previousTools[$name] ?? null;
            if ($base !== null && $now === $before) {
                continue;
            }
            $change = $before === null ? 'added' : ($now === null ? 'removed' : 'name or description changed');
            $this->addSurface($surfaces, $impacts, $path, 'mcp_tool', 'docs/reference/mcp.mdx', "MCP tool {$name} {$change} in {$path}");
        }
    }

    /** @return array<string, array{name: string, title: string, description: string}> */
    private function mcpToolsByName(mixed $manifest): array
    {
        if (! is_array($manifest) || ! is_array($manifest['tools'] ?? null)) {
            return [];
        }
        $tools = [];
        foreach ($manifest['tools'] as $tool) {
            if (! is_array($tool) || ! is_string($tool['name'] ?? null)) {
                continue;
            }
            $tools[$tool['name']] = [
                'name' => $tool['name'],
                'title' => is_string($tool['title'] ?? null) ? $tool['title'] : '',
                'description' => is_string($tool['description'] ?? null) ? $tool['description'] : '',
            ];
        }

        return $tools;
    }

    /**
     * @param  list<array{path: string, kind: string, owner: string, reason: string, generator_status: ?string}>  $surfaces
     * @param  array<string, array{reasons: list<string>}>  $impacts
     * @param  array<string, array{paths: list<string>, names: list<string>}>  $generators
     */
    private function extractMcpSurface(string $path, string $source, array &$surfaces, array &$impacts, array &$generators): void
    {
        if (! str_contains($path, '/Http/Mcp/') && ! str_starts_with($path, 'apps/gateway/resources/mcp/')) {
            return;
        }
        $this->generator($generators, 'bin/mcp-tools', $path);
        $manifest = $this->read('apps/gateway/resources/mcp/tools.json');
        $names = [];
        if (preg_match_all('/[\'"]name[\'"]\s*=>\s*[\'"]([^\'"]+)/', $source, $matches) > 0) {
            $names = $matches[1];
        }
        if ($names === [] && $manifest !== '') {
            preg_match_all('/"name"\s*:\s*"([^"]+)"/', $manifest, $matches);
            $names = $matches[1];
        }
        if ($names === []) {
            $this->addSurface($surfaces, $impacts, $path, 'mcp_tool', 'docs/reference/mcp.mdx', "MCP tool manifest may change via {$path}");

            return;
        }
        foreach ($names as $name) {
            $this->addSurface($surfaces, $impacts, $path, 'mcp_tool', 'docs/reference/mcp.mdx', "MCP tool {$name} via {$path}");
        }
    }

    private function checkGenerator(string $name, ?string $base): string
    {
        $script = $this->root.'/'.$name;
        if (! is_file($script)) {
            return 'missing';
        }
        $arguments = match ($name) {
            'bin/docs-openapi', 'bin/api-fixtures', 'bin/mcp-tools' => ['--check'],
            'bin/cli-contract' => $base === null ? ['--changed'] : ['--changed', $base],
            default => ['--check'],
        };
        $command = implode(' ', array_map(escapeshellarg(...), [$script, ...$arguments]));
        $output = [];
        exec('cd '.escapeshellarg($this->root).' && '.$command.' 2>&1', $output, $status);

        return $status === 0 ? 'passed' : (str_contains(strtolower(implode("\n", $output)), 'stale') ? 'stale' : 'failed');
    }

    private function generatedOwner(string $generator): string
    {
        return match ($generator) {
            'bin/docs-openapi' => 'docs/openapi.json',
            'bin/api-fixtures' => 'packages/php-sdk/fixtures',
            'bin/mcp-tools' => 'apps/gateway/resources/mcp/tools.json',
            'bin/cli-contract' => 'docs/cli',
            default => 'unowned',
        };
    }

    /** @return array<string, list<string>> */
    private function coveringPages(): array
    {
        $result = [];
        $documents = glob($this->root.'/docs/{reference,cli,solutions}/*.{md,mdx}', GLOB_BRACE) ?: [];
        foreach ($documents as $file) {
            $relative = 'docs/'.substr($file, strlen($this->root.'/docs/'));
            if (preg_match('/^covers\s*:\s*\R((?:^[ \t]+.*\R?)*)/m', $this->frontmatter($this->read($relative)), $block) !== 1) {
                continue;
            }
            preg_match_all('/^\s*-\s*[\'"]?([^\'"\r\n]+?)[\'"]?\s*$/m', $block[1], $matches);
            $patterns = [];
            foreach ($matches[1] as $candidate) {
                $pattern = $this->coveragePattern(trim($candidate));
                if ($pattern !== null) {
                    $patterns[] = $pattern;
                }
            }
            if ($patterns !== []) {
                $result[$relative] = array_values(array_unique($patterns));
            }
        }

        return $result;
    }

    /** @return list<array{key: string, path: string, operations: array<string, mixed>}> */
    private function apiOperations(?string $base): array
    {
        $snapshots = [['openapi' => $this->read('docs/openapi.json'), 'navigation' => $this->read('docs/docs.json')]];
        if ($base !== null) {
            $baseOpenApi = $this->gitAllowMissing(['show', $base.':docs/openapi.json']);
            $baseNavigation = $this->gitAllowMissing(['show', $base.':docs/docs.json']);
            if ($baseOpenApi !== null && $baseNavigation !== null) {
                $snapshots[] = ['openapi' => $baseOpenApi, 'navigation' => $baseNavigation];
            }
        }
        $operations = [];
        foreach ($snapshots as $snapshot) {
            $spec = json_decode($snapshot['openapi'], true);
            if (! is_array($spec) || ! is_array($spec['paths'] ?? null)) {
                continue;
            }
            $navigationKeys = $this->apiNavigationKeysFrom($snapshot['navigation']);
            foreach ($spec['paths'] as $uri => $verbs) {
                if (! is_string($uri) || ! is_array($verbs)) {
                    continue;
                }
                foreach ($verbs as $method => $operation) {
                    $upperMethod = strtoupper((string) $method);
                    if (! in_array($upperMethod, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) || ! is_array($operation)) {
                        continue;
                    }
                    $key = $upperMethod.' '.$uri;
                    if (! in_array($key, $navigationKeys, true) || isset($operations[$key])) {
                        continue;
                    }
                    $operation = $this->stringKeyedArray($operation);
                    if ($operation === null) {
                        continue;
                    }
                    $operations[$key] = ['key' => $key, 'path' => $uri, 'operations' => $operation];
                }
            }
        }

        return array_values($operations);
    }

    /** @return list<string> */
    private function apiNavigationKeysFrom(string $contents): array
    {
        $navigation = json_decode($contents, true);
        if (! is_array($navigation) || ! is_array($navigation['navigation'] ?? null)) {
            return [];
        }
        $navigationData = $navigation['navigation'];
        if (! is_array($navigationData['tabs'] ?? null)) {
            return [];
        }
        $keys = [];
        foreach ($navigationData['tabs'] as $tab) {
            if (! is_array($tab) || ! is_array($tab['groups'] ?? null)) {
                continue;
            }
            foreach ($tab['groups'] as $group) {
                if (! is_array($group) || ! is_string($group['openapi'] ?? null) || ! is_array($group['pages'] ?? null)) {
                    continue;
                }
                foreach ($group['pages'] as $page) {
                    if (is_string($page)) {
                        $keys[] = $page;
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<array{key: string, path: string, operations: array<string, mixed>}>  $operations
     * @return list<string>
     */
    private function matchingApiOperations(string $path, string $source, array $operations, ?string $base = null): array
    {
        if ($operations === []) {
            return [];
        }

        $basename = pathinfo($path, PATHINFO_FILENAME);
        $controllerActions = [];
        if (str_contains($path, '/Controllers/Api/')) {
            $controllerActions[$basename] = [];
        } elseif (str_contains($path, '/Requests/')) {
            foreach ($this->git(['ls-files', 'apps/gateway/app/Http/Controllers/Api']) as $controllerPath) {
                $controllerSource = $this->sourceForPath($controllerPath, null) ?? '';
                if (! str_contains($controllerSource, $basename)) {
                    continue;
                }
                preg_match_all('/function\s+([A-Za-z][A-Za-z0-9_]*)\s*\([^)]*\b'.preg_quote($basename, '/').'\b[^)]*\)/s', $controllerSource, $methods);
                if ($methods[1] !== []) {
                    $controllerActions[pathinfo($controllerPath, PATHINFO_FILENAME)] = $methods[1];
                }
            }
        }

        $routeTokens = [];
        $sdkRequest = str_starts_with($path, 'packages/php-sdk/src/Requests/');
        if ($sdkRequest) {
            $sdkMethods = preg_match('/Method::(GET|POST|PUT|PATCH|DELETE)/', $source, $sdkMethod) === 1 ? [strtoupper($sdkMethod[1])] : [];
            if (preg_match_all('/return\s+[\'"]([^\'"]*\/api\/v1\/[^\'"]*)[\'"]/', $source, $sdkPaths) > 0) {
                foreach ($sdkPaths[1] as $sdkPath) {
                    $routeTokens[] = ['path' => preg_replace('/\{\$this->[A-Za-z_][A-Za-z0-9_]*\}/', '{}', trim($sdkPath, '/')) ?? $sdkPath, 'methods' => $sdkMethods, 'controller' => null, 'action' => null];
                }
            }
        }
        $routeFiles = $this->git(['ls-files', 'apps/gateway/routes']);
        if (str_starts_with($path, 'apps/gateway/routes/') && ! in_array($path, $routeFiles, true)) {
            $routeFiles[] = $path;
        }
        $gatewayRouteOrController = str_starts_with($path, 'apps/gateway/routes/') || str_contains($path, '/Controllers/Api/');
        $gatewayRequest = str_contains($path, '/Requests/');
        if ($gatewayRouteOrController || ($gatewayRequest && $controllerActions !== [])) {
            foreach ($routeFiles as $routeFile) {
                $routeSource = $routeFile === $path ? $source : ($this->sourceForPath($routeFile, null) ?? '');
                $routeTokens = [...$routeTokens, ...$this->routeDefinitions($routeSource)];
                if ($base !== null) {
                    $baseRouteSource = $this->gitAllowMissing(['show', $base.':'.$routeFile]);
                    if ($baseRouteSource !== null && $baseRouteSource !== $routeSource) {
                        $routeTokens = [...$routeTokens, ...$this->routeDefinitions($baseRouteSource)];
                    }
                }
            }
        }
        $mustMatchController = str_contains($path, '/Controllers/Api/') || ($gatewayRequest && ! $sdkRequest);

        $keys = [];
        $schemaNames = $this->componentSchemaNames($basename);
        $schemas = $this->apiComponentSchemas($base);
        foreach ($operations as $operation) {
            [$operationMethod] = explode(' ', $operation['key'], 2);
            $operationPath = $this->comparableApiPath($operation['path']);
            foreach ($routeTokens as $token) {
                if ($token['methods'] !== [] && ! in_array($operationMethod, $token['methods'], true)) {
                    continue;
                }
                if ($mustMatchController) {
                    $actions = $controllerActions[$token['controller'] ?? ''] ?? null;
                    if ($actions === null || ($actions !== [] && ! in_array($token['action'], $actions, true))) {
                        continue;
                    }
                }
                $routePath = $this->comparableApiPath($token['path']);
                if ($routePath !== '' && $routePath === $operationPath) {
                    $keys[] = $operation['key'];
                    break;
                }
            }
            $visitedSchemas = [];
            if ($this->containsComponentReference($operation['operations'], $schemaNames, $schemas, $visitedSchemas)) {
                $keys[] = $operation['key'];
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<array{path: string, methods: list<string>, controller: ?string, action: ?string}> */
    private function routeDefinitions(string $source, string $prefix = '', ?string $controller = null): array
    {
        $groupRanges = [];
        $definitions = [];
        $offset = 0;
        while (($start = strpos($source, 'Route::', $offset)) !== false) {
            $tail = substr($source, $start);
            if (preg_match('/\ARoute::(?<chain>[^;]*?)->group\s*\(\s*function\s*\([^)]*\)[^{]*\{/s', $tail, $group, PREG_OFFSET_CAPTURE) !== 1) {
                $offset = $start + strlen('Route::');

                continue;
            }
            $opening = $start + $group[0][1] + strlen($group[0][0]) - 1;
            $closing = $this->matchingBrace($source, $opening);
            if ($closing === null) {
                $offset = $opening + 1;

                continue;
            }
            $chain = $group['chain'][0];
            $groupPrefix = preg_match('/(?:^|->)prefix\s*\(\s*[\'"]([^\'"]*)[\'"]/', $chain, $prefixMatch) === 1 ? $prefixMatch[1] : '';
            $nestedPrefix = trim($prefix.'/'.$groupPrefix, '/');
            $nestedController = preg_match('/(?:^|->)controller\s*\(\s*([A-Za-z_][A-Za-z0-9_]*)::class/', $chain, $controllerMatch) === 1 ? $controllerMatch[1] : $controller;
            $body = substr($source, $opening + 1, $closing - $opening - 1);
            $definitions = [...$definitions, ...$this->routeDefinitions($body, $nestedPrefix, $nestedController)];
            $groupRanges[] = [$start, $closing + 1 - $start];
            $offset = $closing + 1;
        }
        foreach (array_reverse($groupRanges) as [$start, $length]) {
            $source = substr_replace($source, str_repeat(' ', $length), $start, $length);
        }

        preg_match_all('/Route::(?<chain>[^;]*?)(?:->)?(?<method>get|post|put|patch|delete|match|apiResource)\s*\((?<arguments>.*?);/is', $source, $routes, PREG_SET_ORDER);
        foreach ($routes as $route) {
            $method = strtolower($route['method']);
            $arguments = $route['arguments'];
            if ($method === 'match') {
                preg_match('/\A\s*\[([^]]+)\]\s*,\s*[\'"]([^\'"]*)[\'"]/', $arguments, $matchedArguments);
                preg_match_all('/[\'"](get|post|put|patch|delete)[\'"]/', $matchedArguments[1] ?? '', $verbs);
                $methods = array_map(strtoupper(...), $verbs[1]);
                $uri = $matchedArguments[2] ?? '';
            } else {
                $methods = match ($method) {
                    'get' => ['GET'], 'post' => ['POST'], 'put' => ['PUT'], 'patch' => ['PATCH'], 'delete' => ['DELETE'], default => [],
                };
                preg_match('/\A\s*[\'"]([^\'"]*)[\'"]/', $arguments, $uriMatch);
                $uri = $uriMatch[1] ?? '';
            }
            if ($method === 'apiresource') {
                continue;
            }
            $routeController = $controller;
            $action = null;
            if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)::class\s*,\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $arguments, $target) === 1) {
                $routeController = $target[1];
                $action = $target[2];
            } elseif (preg_match('/\[\s*([A-Za-z_][A-Za-z0-9_]*)::class\s*,\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\]/', $arguments, $target) === 1) {
                $routeController = $target[1];
                $action = $target[2];
            } elseif (preg_match('/,\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\)/', $arguments, $target) === 1) {
                $action = $target[1];
            }
            $definitions[] = [
                'path' => trim($prefix.'/'.$uri, '/'),
                'methods' => $methods,
                'controller' => $routeController === null ? null : basename(str_replace('\\', '/', $routeController)),
                'action' => $action,
            ];
        }

        return $definitions;
    }

    private function matchingBrace(string $source, int $opening): ?int
    {
        $depth = 0;
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($source);
        for ($index = $opening; $index < $length; $index++) {
            $character = $source[$index];
            $next = $source[$index + 1] ?? '';
            if ($lineComment) {
                if ($character === "\n") {
                    $lineComment = false;
                }

                continue;
            }
            if ($blockComment) {
                if ($character === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }

                continue;
            }
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if (($character === '/' && $next === '/') || $character === '#') {
                $lineComment = true;

                continue;
            }
            if ($character === '/' && $next === '*') {
                $blockComment = true;
                $index++;

                continue;
            }
            if ($character === '\'' || $character === '"' || $character === '`') {
                $quote = $character;
            } elseif ($character === '{') {
                $depth++;
            } elseif ($character === '}' && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private function comparableApiPath(string $path): string
    {
        $path = preg_replace('/\{[^}]+\}/', '{}', trim($path, '/')) ?? trim($path, '/');
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        return $path;
    }

    private function mcpManifestHasOperation(string $key): bool
    {
        $manifest = json_decode($this->read('apps/gateway/resources/mcp/tools.json'), true);
        if (! is_array($manifest) || ! is_array($manifest['tools'] ?? null)) {
            return false;
        }
        [$method, $path] = explode(' ', $key, 2);
        foreach ($manifest['tools'] as $tool) {
            if (! is_array($tool)) {
                continue;
            }
            $toolMethod = $tool['method'] ?? null;
            $toolPath = $tool['path'] ?? null;
            if (is_string($toolMethod) && is_string($toolPath) && strtoupper($toolMethod) === $method && $toolPath === $path) {
                return true;
            }
        }

        return false;
    }

    private function containsIdentifier(mixed $value, string $identifier): bool
    {
        if (is_string($value)) {
            return $value === $identifier;
        }
        if (! is_array($value)) {
            return false;
        }

        return array_any($value, fn ($child) => $this->containsIdentifier($child, $identifier));
    }

    /** @return list<string> */
    private function componentSchemaNames(string $sourceClass): array
    {
        $names = [$sourceClass];
        foreach (['Data', 'Response'] as $suffix) {
            if (str_ends_with($sourceClass, $suffix)) {
                $names[] = substr($sourceClass, 0, -strlen($suffix));
            }
        }

        return array_values(array_unique($names));
    }

    /** @return array<string, mixed> */
    private function apiComponentSchemas(?string $base): array
    {
        $specs = [$this->read('docs/openapi.json')];
        if ($base !== null) {
            $baseSpec = $this->gitAllowMissing(['show', $base.':docs/openapi.json']);
            if ($baseSpec !== null) {
                $specs[] = $baseSpec;
            }
        }
        $schemas = [];
        foreach ($specs as $contents) {
            $spec = json_decode($contents, true);
            $components = is_array($spec) ? ($spec['components'] ?? null) : null;
            if (is_array($components) && is_array($components['schemas'] ?? null)) {
                $schemas = [...$components['schemas'], ...$schemas];
            }
        }

        return $schemas;
    }

    /**
     * @param  list<string>  $schemaNames
     * @param  array<string, mixed>  $schemas
     * @param  array<string, true>  $visited
     */
    private function containsComponentReference(mixed $value, array $schemaNames, array $schemas, array $visited): bool
    {
        if (! is_array($value)) {
            return false;
        }
        $reference = $value['$ref'] ?? null;
        if (is_string($reference) && preg_match('~^#/components/schemas/([^/]+)$~', $reference, $match) === 1) {
            $name = rawurldecode($match[1]);
            if (in_array($name, $schemaNames, true)) {
                return true;
            }
            if (! isset($visited[$name]) && isset($schemas[$name])) {
                $visited[$name] = true;
                if ($this->containsComponentReference($schemas[$name], $schemaNames, $schemas, $visited)) {
                    return true;
                }
            }
        }

        return array_any($value, fn ($child): bool => $this->containsComponentReference($child, $schemaNames, $schemas, $visited));
    }

    private function isApiPath(string $path): bool
    {
        return str_starts_with($path, 'apps/gateway/routes/')
            || preg_match('#^apps/gateway/app/Http/(Controllers/Api|Requests)/#', $path) === 1
            || preg_match('#^apps/gateway/app/(Data|Enums)/#', $path) === 1
            || preg_match('#^packages/php-sdk/src/(Requests|Responses)/#', $path) === 1;
    }

    /** @param array<string, true> $tracked */
    private function matchesAny(array $tracked, string $pattern): bool
    {
        foreach ($tracked as $path => $_) {
            if ($this->matches($path, $pattern)) {
                return true;
            }
            if (str_ends_with($pattern, '/**') && str_starts_with($path, substr($pattern, 0, -3).'/')) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $path, string $pattern): bool
    {
        foreach ($this->expandBraces($pattern) as $alternative) {
            $regex = preg_quote($alternative, '#');
            $regex = str_replace(['\\*\\*', '\\*'], ['.*', '[^/]*'], $regex);

            if (preg_match('#^'.$regex.'$#', $path) === 1 || (str_ends_with($alternative, '/**') && str_starts_with($path, substr($alternative, 0, -3).'/'))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function expandBraces(string $pattern): array
    {
        if (preg_match('/\{([^{}]+)\}/', $pattern, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [$pattern];
        }

        $alternatives = [];
        $before = substr($pattern, 0, $match[0][1]);
        $after = substr($pattern, $match[0][1] + strlen($match[0][0]));
        foreach (explode(',', $match[1][0]) as $choice) {
            foreach ($this->expandBraces($before.$choice.$after) as $expanded) {
                $alternatives[] = $expanded;
            }
        }

        return $alternatives;
    }

    private function frontmatter(string $content): string
    {
        if (preg_match('/\A---\R(?<frontmatter>.*?)\R---(?:\R|$)/s', $content, $matches) !== 1) {
            return '';
        }

        return $matches['frontmatter'];
    }

    private function coveragePattern(string $pattern): ?string
    {
        $isAbsolute = str_starts_with($pattern, '/') || str_starts_with($pattern, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $pattern) === 1;
        if ($pattern === '' || $isAbsolute) {
            return null;
        }
        $normalized = str_replace('\\', '/', $pattern);
        if (preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
            return null;
        }

        return trim($normalized, '/');
    }

    private function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    private function read(string $path): string
    {
        $content = @file_get_contents($this->root.'/'.$path);

        return $content === false ? '' : $content;
    }

    /** @return list<string> */
    private function committedRatchetPages(): array
    {
        $pages = [];
        $baseline = $this->gitAllowMissing(['merge-base', 'HEAD', 'origin/main']);
        $baseline = $baseline === null || trim($baseline) === '' ? 'HEAD' : trim($baseline);
        $contents = $this->gitAllowMissing(['show', $baseline.':apps/docs/config/docs-covers-ratchet.php']);
        if ($contents !== null) {
            $pages = [...$pages, ...$this->parseRatchetPages($contents)];
        }

        return array_values(array_unique($pages));
    }

    /** @return list<string> */
    private function currentRatchetPages(): array
    {
        return $this->parseRatchetPages($this->read('apps/docs/config/docs-covers-ratchet.php'));
    }

    /** @return list<string> */
    private function parseRatchetPages(string $contents): array
    {
        $contents = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;
        $contents = preg_replace('/^\s*(?:\/\/|#).*$/m', '', $contents) ?? $contents;
        if (preg_match_all('/[\'"](docs\/(?:reference|cli|solutions)\/[^\'"]+)[\'"]/', $contents, $matches) === 0) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /** @return \Generator<int, \SplFileInfo> */
    private function filesUnder(string $directory): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                yield $file;
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function stringKeyedArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                return null;
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param list<string> $args */
    private function gitAllowMissing(array $args): ?string
    {
        $command = 'git -C '.escapeshellarg($this->root).' '.implode(' ', array_map(escapeshellarg(...), $args)).' 2>/dev/null';
        exec($command, $output, $status);

        return $status === 0 ? implode("\n", $output) : null;
    }

    /**
     * Notes that an impacted page needs no update, for the checked-out branch only.
     * They live in the Git directory, so they never reach main or another branch.
     *
     * @return list<string>
     */
    private function unaffectedPageNotes(): array
    {
        $branch = $this->gitAllowMissing(['symbolic-ref', '--quiet', '--short', 'HEAD']);
        if ($branch === null || trim($branch) === '') {
            return [];
        }
        $gitDirectory = $this->git(['rev-parse', '--path-format=absolute', '--git-common-dir'])[0];
        $path = $gitDirectory.'/orbit/docs-unaffected/'.trim($branch).'.txt';

        return is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    private function git(array $args): array
    {
        $command = 'git -C '.escapeshellarg($this->root).' '.implode(' ', array_map(escapeshellarg(...), $args)).' 2>/dev/null';
        $output = [];
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('Unable to read repository paths with git.');
        }

        return array_values(array_filter(array_map(trim(...), $output), static fn (string $value): bool => $value !== ''));
    }
}
