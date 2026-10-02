<?php

declare(strict_types=1);

/**
 * Guest scripts call the Orbit CLI and read its JSON. This test fails when a
 * call uses a command, option, or positional argument the signature does not
 * accept, or when a snippet reads a field the OpenAPI schema does not define.
 *
 * A call is `"$orbit"`, Python `orbit()`, PHP `command([...])` passed to that
 * binary, or a direct `cli/orbit` path. Python field checks follow an `orbit()`
 * result through list elements and helpers such as `unique()`.
 *
 * Keys read from the script's own state file are the only PHP allowlist. The
 * list envelope is the CLI collection (`instances`, `nodes`, `projects`,
 * `routes`, `clusters`) plus `request_id`, and the OpenAPI list fields `data`
 * and `meta`.
 */
it('matches guest orbit commands, arguments, and options to the CLI signatures', function (): void {
    expect(guest_script_command_problems())->toBe([]);
});

it('matches guest response fields to the OpenAPI schemas', function (): void {
    expect(guest_script_field_problems())->toBe([]);
});

it('compiles embedded guest python', function (): void {
    $checked = 0;
    foreach (guest_script_shell_files() as $path) {
        $source = (string) file_get_contents($path);
        if (! str_contains($source, "<<'PYTHON'")) {
            continue;
        }
        $checked++;
        expect(guest_script_python_calls($source)['error'])->toBeNull();
    }
    expect($checked)->toBeGreaterThan(0);
});

it('rejects an extra positional, an undefined python field, and a drifted orbit binary path', function (): void {
    $commands = guest_script_cli_commands();
    $calls = guest_script_orbit_invocations(<<<'SH'
"$orbit" route:create "$id" example.test extra --publication=private --json
/home/orbit/orbit/apps/cli/orbit gateway:status now --json
SH);
    expect($calls)->toHaveCount(2);
    foreach ($calls as $call) {
        $max = $commands[$call['command']]['max'];
        expect($max)->toBeInt()->and($call['positionals'])->toBeGreaterThan($max);
    }

    $python = guest_script_python_calls(<<<'SH'
python3 - <<'PYTHON'
instances=orbit('instance:list')['instances']
def unique(rows, key, value):
    matches = [row for row in rows if row.get(key) == value]
    return matches[0] if matches else None
dev=unique(instances, 'name', 'e2e-dev')
print(dev['hostname'])
PYTHON

SH);
    expect($python['error'])->toBeNull()
        ->and(implode("\n", $python['fields']))->toContain('hostname')
        ->toContain('Instance');
});

/**
 * @return list<string>
 */
function guest_script_command_problems(): array
{
    $commands = guest_script_cli_commands();
    $problems = [];
    foreach (guest_script_shell_files() as $path) {
        $source = (string) file_get_contents($path);
        $python = guest_script_python_calls($source);
        if ($python['error'] !== null) {
            $problems[] = basename($path).' python: '.$python['error'];
        }
        foreach ([...guest_script_orbit_invocations($source), ...guest_script_php_orbit_calls($source), ...$python['calls']] as $call) {
            $location = basename($path).':'.$call['line'].' '.$call['command'];
            if (! isset($commands[$call['command']])) {
                $problems[] = "{$location} is not a CLI command";

                continue;
            }
            $contract = $commands[$call['command']];
            foreach ($call['options'] as $option) {
                if (! in_array($option, $contract['options'], true)) {
                    $problems[] = "{$location} has no --{$option} option";
                }
            }
            $count = $call['positionals'];
            $max = $contract['max'];
            if ($call['unbounded']) {
                if ($max !== null && $count > $max) {
                    $problems[] = "{$location} has {$count} positional arguments";
                }
            } elseif ($count < $contract['min'] || ($max !== null && $count > $max)) {
                $problems[] = "{$location} has {$count} positional arguments";
            }
        }
        foreach ($python['unparsed'] as $unparsed) {
            $problems[] = basename($path).':'.$unparsed;
        }
    }

    return $problems;
}

/**
 * @return list<string>
 */
function guest_script_field_problems(): array
{
    $properties = guest_script_schema_properties();
    $problems = [];
    foreach (guest_script_shell_files() as $path) {
        $source = (string) file_get_contents($path);
        foreach (guest_script_php_snippets($source) as $snippet) {
            $allowed = guest_script_allowed_keys($snippet, $properties);
            if ($allowed === null) {
                foreach (guest_script_php_keys($snippet['code']) as $key) {
                    if (in_array($key, ['clusters', 'instances', 'nodes', 'projects', 'routes'], true)) {
                        $problems[] = basename($path).':'.$snippet['line']." reads {$key} without a mapped response";
                    }
                }

                continue;
            }
            foreach (guest_script_php_keys($snippet['code']) as $key) {
                if (! isset($allowed[$key])) {
                    $problems[] = basename($path).':'.$snippet['line']." reads {$key}";
                }
            }
        }
        $python = guest_script_python_calls($source);
        if ($python['error'] !== null) {
            $problems[] = basename($path).' python: '.$python['error'];
        }
        foreach ($python['fields'] as $field) {
            $problems[] = basename($path).':'.$field;
        }
    }

    return array_values(array_unique($problems));
}

/**
 * @return list<string>
 */
function guest_script_shell_files(): array
{
    $paths = glob(dirname(__DIR__, 3).'/resources/guest/*.sh');
    expect($paths)->not->toBeFalse();
    sort($paths);

    return $paths;
}

/**
 * @return array<string, array{options: list<string>, min: int, max: int|null}>
 */
function guest_script_cli_commands(): array
{
    $commands = [];
    $files = glob(dirname(__DIR__, 5).'/apps/cli/app/Commands/*.php');
    $nested = glob(dirname(__DIR__, 5).'/apps/cli/app/Commands/*/*.php');
    expect($files)->not->toBeFalse()->and($nested)->not->toBeFalse();
    foreach ([...$files, ...$nested] as $path) {
        $signature = guest_script_php_property((string) file_get_contents($path), 'signature');
        if ($signature === null) {
            continue;
        }
        [$name, $contract] = guest_script_signature_contract($signature);
        expect($commands)->not->toHaveKey($name);
        $commands[$name] = $contract;
    }

    return $commands;
}

/**
 * @return array{0: string, 1: array{options: list<string>, min: int, max: int|null}}
 */
function guest_script_signature_contract(string $signature): array
{
    $name = preg_split('/\s+/', trim($signature))[0] ?? '';
    $options = [];
    $min = 0;
    $max = 0;
    $variadic = false;
    preg_match_all('/\{\s*(.*?)\s*\}/s', $signature, $tokens);
    foreach ($tokens[1] as $token) {
        $token = trim(preg_replace('/\s+/', ' ', $token) ?? $token);
        $spec = explode(' : ', $token, 2)[0];
        if (str_starts_with($spec, '--')) {
            $spec = preg_replace('/^-{2,}/', '', $spec) ?? $spec;
            if (str_contains($spec, '|')) {
                $spec = explode('|', $spec, 2)[1];
            }
            $option = explode('=', explode('*', $spec, 2)[0], 2)[0];
            $options[] = trim($option);

            continue;
        }
        $isVariadic = str_ends_with($spec, '*');
        if ($isVariadic) {
            $spec = substr($spec, 0, -1);
            $variadic = true;
        }
        $optional = str_contains($spec, '=') || str_ends_with($spec, '?');
        if (! $optional) {
            $min++;
        }
        if (! $isVariadic) {
            $max++;
        }
    }

    return [$name, [
        'options' => $options,
        'min' => $min,
        'max' => $variadic ? null : $max,
    ]];
}

function guest_script_php_property(string $source, string $name): ?string
{
    $found = preg_match('/protected\s+\$'.preg_quote($name, '/').'\s*=\s*/', $source, $match, PREG_OFFSET_CAPTURE);
    if ($found !== 1) {
        return null;
    }
    $start = $match[0][1] + strlen($match[0][0]);
    if (str_starts_with(substr($source, $start), '<<<')) {
        $opener = preg_match(
            "/<<<[ \t]*('?)([A-Za-z_][A-Za-z0-9_]*)\\1[ \t]*\r?\n/",
            $source,
            $heredoc,
            PREG_OFFSET_CAPTURE,
            $start,
        );
        if ($opener !== 1) {
            return null;
        }
        $bodyAt = $heredoc[0][1] + strlen($heredoc[0][0]);
        $closer = preg_match('/(?m)^'.preg_quote($heredoc[2][0], '/').'(?=\s*;)/', $source, $end, PREG_OFFSET_CAPTURE, $bodyAt);
        if ($closer !== 1) {
            return null;
        }

        return substr($source, $bodyAt, $end[0][1] - $bodyAt);
    }
    $quote = $source[$start];
    if ($quote !== "'" && $quote !== '"') {
        return null;
    }
    $out = '';
    $length = strlen($source);
    for ($i = $start + 1; $i < $length; $i++) {
        $char = $source[$i];
        if ($char === '\\' && $i + 1 < $length && ($quote === '"' || str_contains("\\'", $source[$i + 1]))) {
            $out .= $source[$i + 1];
            $i++;

            continue;
        }
        if ($char === $quote) {
            return $out;
        }
        $out .= $char;
    }

    return null;
}

/**
 * @return list<array{line: int, command: string, options: list<string>, positionals: int, unbounded: bool}>
 */
function guest_script_orbit_invocations(string $source): array
{
    $marker = '"$orbit"';
    $calls = [];
    $length = strlen($source);
    $line = 1;
    $quote = null;
    for ($i = 0; $i < $length; $i++) {
        $char = $source[$i];
        if ($char === "\n") {
            $line++;
        }
        if ($quote === "'") {
            if ($char === "'") {
                $quote = null;
            }

            continue;
        }
        if ($quote === '"') {
            if ($char === '\\') {
                $i++;

                continue;
            }
            if ($char === '"') {
                $quote = null;
            }

            continue;
        }
        if ($char === "'") {
            $quote = "'";

            continue;
        }
        if ($char === '"') {
            if (! str_starts_with(substr($source, $i), $marker)) {
                $quote = '"';

                continue;
            }
            if (guest_script_orbit_is_invocation($source, $i)) {
                $calls[] = guest_script_orbit_call($source, $i + strlen($marker), $line);
            }
            $i += strlen($marker) - 1;

            continue;
        }
        if ($char === '/' && ($i === 0 || str_contains(" \t\n;|&(", $source[$i - 1]))) {
            $end = $i;
            while ($end < $length && ! str_contains(" \t\n;|&)<>'\"", $source[$end])) {
                $end++;
            }
            $segments = explode('/', substr($source, $i, $end - $i));
            $base = array_pop($segments);
            if ($base === 'orbit' && array_pop($segments) === 'cli') {
                $calls[] = guest_script_orbit_call($source, $end, $line);
            }
            if ($end > $i) {
                $i = $end - 1;
            }

            continue;
        }
        if ($char === '#') {
            $next = strpos($source, "\n", $i);
            if ($next === false) {
                break;
            }
            $i = $next - 1;
        }
    }

    return array_values(array_filter(
        $calls,
        static fn (array $call): bool => $call['command'] !== '',
    ));
}

function guest_script_orbit_is_invocation(string $source, int $offset): bool
{
    $index = $offset - 1;
    while ($index >= 0 && ($source[$index] === ' ' || $source[$index] === "\t")) {
        $index--;
    }
    if ($index < 0) {
        return true;
    }
    $previous = $source[$index];

    return $previous === "\n" || $previous === ';' || $previous === '|' || $previous === '&' || $previous === '(' || $previous === '=';
}

/**
 * @return array{line: int, command: string, options: list<string>, positionals: int, unbounded: bool}
 */
function guest_script_orbit_call(string $source, int $offset, int $line): array
{
    $words = [];
    $length = strlen($source);
    $index = $offset;
    while ($index < $length) {
        while ($index < $length && ($source[$index] === ' ' || $source[$index] === "\t")) {
            $index++;
        }
        if ($index >= $length || str_contains("\n;|&)<>", $source[$index])) {
            break;
        }
        $word = '';
        while ($index < $length && ! str_contains(" \t\n;|&)<>", $source[$index])) {
            $char = $source[$index];
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $index++;
                while ($index < $length && $source[$index] !== $quote) {
                    if ($source[$index] === '\\' && $index + 1 < $length) {
                        $word .= $source[$index + 1];
                        $index += 2;

                        continue;
                    }
                    $word .= $source[$index];
                    $index++;
                }
                $index++;

                continue;
            }
            $word .= $char;
            $index++;
        }
        if ($word !== '') {
            $redirects = ($index < $length && $source[$index] === '>')
                || ($index + 1 < $length && $source[$index] === '&' && $source[$index + 1] === '>');
            if (preg_match('/\A\d+\z/', $word) === 1 && $redirects) {
                continue;
            }
            $words[] = $word;
        }
    }
    $command = $words[0] ?? '';
    if (preg_match('/\A[a-z][a-z0-9]*(?::[a-z0-9-]+)*\z/', $command) !== 1) {
        $command = '';
    }
    $options = [];
    $positionals = 0;
    foreach (array_slice($words, 1) as $word) {
        if (! str_starts_with($word, '--')) {
            $positionals++;

            continue;
        }
        $option = explode('=', substr($word, 2), 2)[0];
        if ($option !== '') {
            $options[] = $option;
        }
    }

    return [
        'line' => $line,
        'command' => $command,
        'options' => $options,
        'positionals' => $positionals,
        'unbounded' => false,
    ];
}

/**
 * @return list<array{line: int, command: string, options: list<string>, positionals: int, unbounded: bool}>
 */
function guest_script_php_orbit_calls(string $source): array
{
    $calls = [];
    $length = strlen($source);
    $offset = 0;
    while (($at = strpos($source, 'command(', $offset)) !== false) {
        $cursor = $at + strlen('command(');
        while ($cursor < $length && ($source[$cursor] === ' ' || $source[$cursor] === "\n" || $source[$cursor] === "\t")) {
            $cursor++;
        }
        if ($cursor >= $length || $source[$cursor] !== '[') {
            $offset = $at + strlen('command(');

            continue;
        }
        $elements = guest_script_php_array_elements($source, $cursor);
        if ($elements === null) {
            $offset = $cursor + 1;

            continue;
        }
        $call = guest_script_php_orbit_call($elements, substr_count(substr($source, 0, $at), "\n") + 1);
        if ($call !== null) {
            $calls[] = $call;
        }
        $offset = $cursor + 1;
    }

    return $calls;
}

/**
 * @return list<string>|null
 */
function guest_script_php_array_elements(string $source, int $openBracket): ?array
{
    $length = strlen($source);
    $depth = 1;
    $quote = null;
    $elements = [];
    $elementStart = $openBracket + 1;
    for ($index = $openBracket + 1; $index < $length; $index++) {
        $char = $source[$index];
        if ($quote !== null) {
            if ($char === '\\' && $index + 1 < $length) {
                $index++;

                continue;
            }
            if ($char === $quote) {
                $quote = null;
            }

            continue;
        }
        if ($char === "'" || $char === '"') {
            $quote = $char;

            continue;
        }
        if ($char === '[' || $char === '(') {
            $depth++;

            continue;
        }
        if ($char === ']' || $char === ')') {
            $depth--;
            if ($depth === 0 && $char === ']') {
                $elements[] = trim(substr($source, $elementStart, $index - $elementStart));

                return array_values(array_filter($elements, static fn (string $element): bool => $element !== ''));
            }

            continue;
        }
        if ($char === ',' && $depth === 1) {
            $elements[] = trim(substr($source, $elementStart, $index - $elementStart));
            $elementStart = $index + 1;
        }
    }

    return null;
}

/**
 * @param  list<string>  $elements
 * @return array{line: int, command: string, options: list<string>, positionals: int, unbounded: bool}|null
 */
function guest_script_php_orbit_call(array $elements, int $line): ?array
{
    $command = $elements[0] ?? '';
    if (preg_match("/\A'([a-z][a-z0-9]*(?::[a-z0-9-]+)*)'\z/", $command, $match) !== 1) {
        return null;
    }
    $options = [];
    $positionals = 0;
    $unbounded = false;
    foreach (array_slice($elements, 1) as $element) {
        if (preg_match("/\A'--([A-Za-z0-9-]+)(?:=.*)?'\z/s", $element, $option) === 1) {
            $options[] = $option[1];

            continue;
        }
        if (str_starts_with($element, '...')) {
            $unbounded = true;

            continue;
        }
        $positionals++;
    }

    return [
        'line' => $line,
        'command' => $match[1],
        'options' => $options,
        'positionals' => $positionals,
        'unbounded' => $unbounded,
    ];
}

/**
 * @return array<string, array<string, true>>
 */
function guest_script_schema_properties(): array
{
    $document = json_decode(
        (string) file_get_contents(dirname(__DIR__, 5).'/docs/openapi.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $schemas = $document['components']['schemas'] ?? [];
    expect($schemas)->toBeArray();
    $properties = [];
    foreach (['Node', 'Project', 'Instance', 'Route', 'RouteTarget', 'Cluster'] as $name) {
        $properties[$name] = [];
        $pending = [$name];
        $seen = [];
        while ($pending !== []) {
            $schema = array_pop($pending);
            if (isset($seen[$schema]) || ! isset($schemas[$schema]['properties'])) {
                continue;
            }
            $seen[$schema] = true;
            foreach ($schemas[$schema]['properties'] as $key => $definition) {
                $properties[$name][$key] = true;
                foreach (guest_script_schema_refs($definition) as $reference) {
                    $pending[] = $reference;
                }
            }
        }
    }

    return $properties;
}

/**
 * @return list<string>
 */
function guest_script_schema_refs(mixed $definition): array
{
    if (! is_array($definition)) {
        return [];
    }
    $refs = [];
    if (isset($definition['$ref']) && is_string($definition['$ref'])) {
        $refs[] = basename($definition['$ref']);
    }
    foreach ($definition as $value) {
        if (is_array($value)) {
            $refs = [...$refs, ...guest_script_schema_refs($value)];
        }
    }

    return $refs;
}

/**
 * @param  array{line: int, code: string, resources: list<string>, state: bool}  $snippet
 * @param  array<string, array<string, true>>  $properties
 * @return array<string, true>|null
 */
function guest_script_allowed_keys(array $snippet, array $properties): ?array
{
    if ($snippet['resources'] === [] && ! $snippet['state']) {
        return null;
    }
    $allowed = [];
    foreach ($snippet['resources'] as $resource) {
        $allowed += $properties[$resource];
        foreach (guest_script_envelope_keys($resource) as $key) {
            $allowed[$key] = true;
        }
    }
    if ($snippet['state']) {
        foreach (guest_script_state_keys() as $key) {
            $allowed[$key] = true;
        }
    }

    return $allowed;
}

/**
 * @return list<string>
 */
function guest_script_envelope_keys(string $resource): array
{
    $collection = match ($resource) {
        'Node' => 'nodes',
        'Project' => 'projects',
        'Instance' => 'instances',
        'Route' => 'routes',
        'Cluster' => 'clusters',
        default => null,
    };

    return array_values(array_filter([
        $collection,
        'data',
        'meta',
        'request_id',
    ]));
}

/**
 * The script's own state file, including the production placement it stores.
 *
 * @return list<string>
 */
function guest_script_state_keys(): array
{
    return [
        'shape',
        'project_id',
        'node_id',
        'name',
        'checkout_path',
        'effective_root',
        'production',
        'layout',
        'instance_id',
        'user',
        'home',
        'environment_path',
        'database_path',
        'service',
        'socket',
        'current_target',
        'domain',
    ];
}

/**
 * @return list<array{line: int, code: string, resources: list<string>, state: bool}>
 */
function guest_script_php_snippets(string $source): array
{
    $variables = [];
    $functions = guest_script_php_functions($source);
    $snippets = [];
    $lines = preg_split("/\R/", $source) ?: [];
    $functionDepth = 0;
    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        if (preg_match('/^[ \t]*[A-Za-z_][A-Za-z0-9_]*\(\) \{$/', $line) === 1) {
            $functionDepth++;
        }
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)=\$\("\$orbit"\s+([a-z0-9:-]+)/', $line, $assignments, PREG_SET_ORDER) > 0) {
            foreach ($assignments as $assignment) {
                $variables[$assignment[1]] = guest_script_command_resource($assignment[2]);
            }
        }
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)=\$\(instance_list\b/', $line, $assignments, PREG_SET_ORDER) > 0) {
            foreach ($assignments as $assignment) {
                $variables[$assignment[1]] = ['Instance'];
            }
        }
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)=\$\(typed_cluster_envelope\b/', $line, $assignments, PREG_SET_ORDER) > 0) {
            foreach ($assignments as $assignment) {
                $variables[$assignment[1]] = ['Cluster'];
            }
        }
        if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)=\$([A-Za-z_][A-Za-z0-9_]*)\s*$/', $line, $alias) === 1 && isset($variables[$alias[2]])) {
            $variables[$alias[1]] = $variables[$alias[2]];
        }
        if ($functionDepth === 0 && str_contains($line, "php -r '")) {
            $code = guest_script_extract_php($line);
            if ($code === null) {
                continue;
            }
            $snippets[] = [
                'line' => $lineNumber,
                'code' => $code,
                'resources' => guest_script_snippet_resources(guest_script_line_resources($line, $variables), $code),
                'state' => guest_script_line_reads_state($line, $code),
            ];
        }
        if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)((?:\s+(?:"[^"]*"|\$[A-Za-z_][A-Za-z0-9_]*|[A-Za-z0-9_.-]+))*)\s*<<<"\$([A-Za-z_][A-Za-z0-9_]*)"/', $line, $call) === 1
            && isset($functions[$call[1]])) {
            $snippets[] = [
                'line' => $lineNumber,
                'code' => $functions[$call[1]]['code'],
                'resources' => guest_script_snippet_resources($variables[$call[3]] ?? [], $functions[$call[1]]['code']),
                'state' => $functions[$call[1]]['state'],
            ];
        }
        if ($functionDepth > 0 && preg_match('/^[ \t]*\}$/', $line) === 1) {
            $functionDepth--;
        }
    }
    if (preg_match("/<<'PHP'\\n(.*?)\\nPHP\\n/s", $source, $heredoc) === 1
        && str_contains($heredoc[1], "command(['node:list'])")) {
        $line = substr_count(substr($source, 0, (int) strpos($source, "<<'PHP'")), "\n") + 1;
        $body = array_values(array_filter(
            preg_split("/\R/", $heredoc[1]) ?: [],
            static fn (string $bodyLine): bool => ! str_contains($bodyLine, '$failure'),
        ));
        $snippets[] = [
            'line' => $line,
            'code' => implode("\n", $body),
            'resources' => ['Node', 'Cluster'],
            'state' => false,
        ];
    }

    return $snippets;
}

/**
 * @return array<string, array{code: string, state: bool}>
 */
function guest_script_php_functions(string $source): array
{
    $functions = [];
    $lines = preg_split("/\R/", $source) ?: [];
    $count = count($lines);
    for ($index = 0; $index < $count; $index++) {
        if (preg_match('/^(\s*)([A-Za-z_][A-Za-z0-9_]*)\(\) \{$/', $lines[$index], $header) !== 1) {
            continue;
        }
        $body = [];
        $indent = strlen($header[1]);
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            if (preg_match('/^(\s*)\}$/', $lines[$cursor], $close) === 1 && strlen($close[1]) === $indent) {
                break;
            }
            $body[] = $lines[$cursor];
        }
        $code = guest_script_extract_php(implode("\n", $body));
        if ($code !== null) {
            $functions[$header[2]] = [
                'code' => $code,
                'state' => str_contains($code, 'base64_decode($argv'),
            ];
        }
        $index = $cursor;
    }

    return $functions;
}

function guest_script_extract_php(string $text): ?string
{
    $start = strpos($text, "php -r '");
    if ($start === false) {
        return null;
    }
    $codeAt = $start + 8;
    $end = strpos($text, "'", $codeAt);
    if ($end === false) {
        return null;
    }

    return substr($text, $codeAt, $end - $codeAt);
}

/**
 * @param  array<string, list<string>>  $variables
 * @return list<string>
 */
function guest_script_line_resources(string $line, array $variables): array
{
    $resources = [];
    if (preg_match('/"\$orbit"\s+([a-z0-9:-]+)/', $line, $command) === 1) {
        $resources = [...$resources, ...guest_script_command_resource($command[1])];
    }
    if (str_contains($line, 'instance_list') || str_contains($line, '<(instance_list)')) {
        $resources[] = 'Instance';
    }
    if (preg_match_all('/<<<"\$([A-Za-z_][A-Za-z0-9_]*)"/', $line, $redirects) === 1 || isset($redirects[1])) {
        foreach ($redirects[1] ?? [] as $variable) {
            $resources = [...$resources, ...($variables[$variable] ?? [])];
        }
    }

    return array_values(array_unique($resources));
}

/**
 * @return list<string>
 */
function guest_script_command_resource(string $command): array
{
    return match (true) {
        str_starts_with($command, 'node:') && ! str_starts_with($command, 'node:role') && ! str_starts_with($command, 'node:access') => ['Node'],
        str_starts_with($command, 'project:') => ['Project'],
        str_starts_with($command, 'instance:') => ['Instance'],
        str_starts_with($command, 'route:') => ['Route'],
        str_starts_with($command, 'cluster:') => ['Cluster'],
        default => [],
    };
}

/**
 * A cluster check also decodes the node inventory from argv.
 *
 * @param  list<string>  $resources
 * @return list<string>
 */
function guest_script_snippet_resources(array $resources, string $code): array
{
    if (str_contains($code, 'json_decode($argv') && str_contains($code, '["nodes"]')) {
        $resources[] = 'Node';
    }

    return array_values(array_unique($resources));
}

function guest_script_line_reads_state(string $line, string $code): bool
{
    return str_contains($line, 'sample_state_json')
        || (str_contains($code, 'base64_decode($argv') && str_contains($code, 'current_target'));
}

/**
 * @return list<string>
 */
function guest_script_php_keys(string $code): array
{
    $keys = [];
    $patterns = [
        '/(?:\$[A-Za-z_][A-Za-z0-9_]*|\]|\))\[\s*([\'"])([^\'"]+)\1\s*\]/',
        '/\barray_key_exists\(\s*([\'"])([^\'"]+)\1/',
        '/\bproperty_exists\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*,\s*([\'"])([^\'"]+)\1/',
    ];
    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $code, $matches);
        foreach ($matches[2] as $key) {
            $keys[] = $key;
        }
    }
    preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*\()/', $code, $properties);
    foreach ($properties[1] as $property) {
        $keys[] = $property;
    }

    return array_values(array_unique($keys));
}

/**
 * @return array{
 *     fields: array<string, list<string>>,
 *     kinds: array<string, array{kind: string, schema?: string}>,
 *     open: list<string>
 * }
 */
function guest_script_schema_bundle(): array
{
    $document = json_decode(
        (string) file_get_contents(dirname(__DIR__, 5).'/docs/openapi.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $schemas = $document['components']['schemas'] ?? [];
    expect($schemas)->toBeArray();
    $fields = [];
    $kinds = [];
    $open = [];
    $register = function (string $name, array $schema) use (&$register, &$fields, &$kinds, &$open): void {
        if (array_key_exists($name, $fields)) {
            return;
        }
        $fields[$name] = [];
        if (($schema['additionalProperties'] ?? false) === true) {
            $open[] = $name;
        }
        $properties = $schema['properties'] ?? [];
        if (! is_array($properties)) {
            return;
        }
        foreach ($properties as $property => $definition) {
            if (! is_string($property) || ! is_array($definition)) {
                continue;
            }
            $fields[$name][] = $property;
            $kind = guest_script_property_kind($definition);
            if ($kind['kind'] === 'inline') {
                $child = $name.'.'.$property;
                $kinds[$name.'.'.$property] = ['kind' => 'object', 'schema' => $child];
                $register($child, $definition);

                continue;
            }
            $kinds[$name.'.'.$property] = $kind;
        }
    };
    foreach ($schemas as $name => $schema) {
        if (
            is_string($name)
            && is_array($schema)
            && (isset($schema['properties']) || isset($schema['additionalProperties']))
        ) {
            $register($name, $schema);
        }
    }

    return ['fields' => $fields, 'kinds' => $kinds, 'open' => $open];
}

/**
 * @param  array<string, mixed>  $definition
 * @return array{kind: string, schema?: string}
 */
function guest_script_property_kind(array $definition): array
{
    $definition = guest_script_unwrap_schema($definition);
    if (isset($definition['$ref']) && is_string($definition['$ref'])) {
        return ['kind' => 'object', 'schema' => basename($definition['$ref'])];
    }
    $type = $definition['type'] ?? null;
    if (is_array($type)) {
        $types = array_values(array_filter($type, static fn (mixed $item): bool => $item !== 'null'));
        $type = $types[0] ?? null;
    }
    if ($type === 'array') {
        $items = $definition['items'] ?? null;
        if (is_array($items)) {
            $items = guest_script_unwrap_schema($items);
            if (isset($items['$ref']) && is_string($items['$ref'])) {
                return ['kind' => 'array', 'schema' => basename($items['$ref'])];
            }
        }

        return ['kind' => 'array'];
    }
    if ($type === 'object' || isset($definition['properties']) || isset($definition['additionalProperties'])) {
        $additional = ($definition['additionalProperties'] ?? false) === true;
        $hasProperties = isset($definition['properties']) && is_array($definition['properties']) && $definition['properties'] !== [];
        if ($additional && ! $hasProperties) {
            return ['kind' => 'open'];
        }
        if ($hasProperties) {
            return ['kind' => 'inline'];
        }
        if ($additional) {
            return ['kind' => 'open'];
        }
    }

    return ['kind' => 'scalar'];
}

/**
 * @param  array<string, mixed>  $definition
 * @return array<string, mixed>
 */
function guest_script_unwrap_schema(array $definition): array
{
    foreach (['oneOf', 'anyOf', 'allOf'] as $key) {
        if (! isset($definition[$key]) || ! is_array($definition[$key])) {
            continue;
        }
        foreach ($definition[$key] as $option) {
            if (is_array($option) && ($option['type'] ?? null) !== 'null') {
                return guest_script_unwrap_schema($option);
            }
        }
    }

    return $definition;
}

/**
 * CLI list commands use a collection name. OpenAPI lists use `data` instead.
 *
 * @return array<string, array{schema: string, list: string|null}>
 */
function guest_script_response_shapes(): array
{
    return [
        'cluster:list' => ['schema' => 'Cluster', 'list' => 'clusters'],
        'database:create' => ['schema' => 'DatabaseConnection', 'list' => null],
        'database:list' => ['schema' => 'DatabaseConnection', 'list' => 'connections'],
        'doctor' => ['schema' => 'DoctorReport', 'list' => null],
        'instance:clone' => ['schema' => 'Instance', 'list' => null],
        'instance:create' => ['schema' => 'Instance', 'list' => null],
        'instance:list' => ['schema' => 'Instance', 'list' => 'instances'],
        'node:list' => ['schema' => 'Node', 'list' => 'nodes'],
        'process:create' => ['schema' => 'Process', 'list' => null],
        'process:list' => ['schema' => 'Process', 'list' => 'processes'],
        'process:start' => ['schema' => 'Process', 'list' => null],
        'project:create' => ['schema' => 'Project', 'list' => null],
        'project:list' => ['schema' => 'Project', 'list' => 'projects'],
        'route:create' => ['schema' => 'Route', 'list' => null],
        'route:list' => ['schema' => 'Route', 'list' => 'routes'],
        'schedule:create' => ['schema' => 'Schedule', 'list' => null],
        'schedule:list' => ['schema' => 'Schedule', 'list' => 'schedules'],
        'schedule:show' => ['schema' => 'Schedule', 'list' => null],
    ];
}

/**
 * @return array{error: ?string, calls: list<array{line: int, command: string, options: list<string>, positionals: int, unbounded: bool}>, unparsed: list<string>, fields: list<string>}
 */
function guest_script_python_calls(string $source): array
{
    $empty = ['error' => null, 'calls' => [], 'unparsed' => [], 'fields' => []];
    if (preg_match("/<<'PYTHON'\\n(.*)\nPYTHON\\n/s", $source, $heredoc) !== 1) {
        return $empty;
    }
    $lineOffset = substr_count(substr($source, 0, (int) strpos($source, "<<'PYTHON'")), "\n") + 1;
    $process = proc_open(
        ['python3', '-c', guest_script_python_parser()],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (! is_resource($process)) {
        return ['error' => 'python3 did not start', 'calls' => [], 'unparsed' => [], 'fields' => []];
    }
    $bundle = guest_script_schema_bundle();
    fwrite($pipes[0], json_encode([
        'source' => $heredoc[1],
        'fields' => $bundle['fields'],
        'kinds' => $bundle['kinds'],
        'open' => $bundle['open'],
        'responses' => guest_script_response_shapes(),
    ], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode(is_string($stdout) ? $stdout : '', true);
    if ($exit !== 0 || ! is_array($decoded)) {
        $message = trim(is_string($stderr) ? $stderr : '');

        return ['error' => $message !== '' ? $message : 'python3 did not parse the guest script', 'calls' => [], 'unparsed' => [], 'fields' => []];
    }
    $calls = [];
    foreach ($decoded['calls'] ?? [] as $call) {
        if (! is_array($call)) {
            continue;
        }
        $calls[] = [
            'line' => $lineOffset + (int) ($call['line'] ?? 0),
            'command' => (string) ($call['command'] ?? ''),
            'options' => array_values(array_map(strval(...), is_array($call['options'] ?? null) ? $call['options'] : [])),
            'positionals' => (int) ($call['positionals'] ?? 0),
            'unbounded' => ($call['unbounded'] ?? false) === true,
        ];
    }
    $fields = [];
    foreach ($decoded['fields'] ?? [] as $field) {
        if (! is_array($field)) {
            continue;
        }
        $fields[] = ($lineOffset + (int) ($field['line'] ?? 0)).' '.(string) ($field['message'] ?? '');
    }

    return [
        'error' => is_string($decoded['error'] ?? null) ? $decoded['error'] : null,
        'calls' => $calls,
        'unparsed' => array_values(array_map(strval(...), is_array($decoded['unparsed'] ?? null) ? $decoded['unparsed'] : [])),
        'fields' => $fields,
    ];
}

function guest_script_python_parser(): string
{
    return <<<'PYTHON'
import ast, json, re, sys
payload = json.loads(sys.stdin.read())
source = payload["source"]
schema_fields = payload["fields"]
schema_kinds = payload["kinds"]
open_schemas = set(payload["open"])
responses = payload["responses"]
try:
    tree = ast.parse(source)
except SyntaxError as error:
    print(json.dumps({"error": f"line {error.lineno}: {error.msg}", "calls": [], "unparsed": [], "fields": []}))
    sys.exit(0)

def static_string(node, env):
    if isinstance(node, ast.Constant) and isinstance(node.value, str):
        return node.value
    if isinstance(node, ast.BinOp) and isinstance(node.op, ast.Add):
        left = static_string(node.left, env)
        right = static_string(node.right, env)
        if left is None:
            return None
        return left + (right or "")
    if isinstance(node, ast.Name) and node.id in env:
        return env[node.id]
    return None

def option_name(value):
    if not isinstance(value, str) or not value.startswith("--"):
        return None
    name = value[2:].split("=", 1)[0]
    return name or None

def expression_text(node):
    return ast.get_source_segment(source, node) or ""

env = {}
args_tokens = []
calls = []
unparsed = []
seen = set()

def tokens_from(elts):
    tokens = []
    for elt in elts:
        if isinstance(elt, ast.Starred) and isinstance(elt.value, (ast.GeneratorExp, ast.ListComp)):
            tokens.append(static_string(elt.value.elt, env))
        else:
            tokens.append(static_string(elt, env))
    return tokens

def remember(node, lineno):
    key = (lineno, ast.dump(node))
    if key in seen:
        return
    seen.add(key)
    command = None
    options = []
    positionals = 0
    unbounded = False
    starred_args = any(isinstance(arg, ast.Starred) and isinstance(arg.value, ast.Name) and arg.value.id == "args" for arg in node.args)
    if starred_args:
        if args_tokens:
            command = args_tokens[0]
            for token in args_tokens[1:]:
                name = option_name(token) if isinstance(token, str) else None
                if name:
                    options.append(name)
                else:
                    positionals += 1
        else:
            unbounded = True
    else:
        if node.args:
            command = static_string(node.args[0], env)
        for arg in node.args[1:]:
            if isinstance(arg, ast.Starred) and isinstance(arg.value, (ast.GeneratorExp, ast.ListComp)):
                token = static_string(arg.value.elt, env)
                name = option_name(token) if isinstance(token, str) else None
                text = expression_text(arg)
                if name:
                    options.append(name)
                elif "--" in text:
                    unparsed.append(f"line {lineno}: unparsed orbit argument")
                else:
                    unbounded = True
            elif isinstance(arg, ast.Starred):
                unbounded = True
            else:
                token = static_string(arg, env)
                name = option_name(token) if isinstance(token, str) else None
                text = expression_text(arg)
                if name:
                    options.append(name)
                elif "--" in text:
                    unparsed.append(f"line {lineno}: unparsed orbit argument")
                else:
                    positionals += 1
    if isinstance(command, str) and re.fullmatch(r"[a-z][a-z0-9]*(?::[a-z0-9-]+)*", command):
        calls.append({"line": lineno, "command": command, "options": options, "positionals": positionals, "unbounded": unbounded})

def handle(stmt):
    global args_tokens
    if isinstance(stmt, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)):
        return
    if isinstance(stmt, ast.Assign) and len(stmt.targets) == 1 and isinstance(stmt.targets[0], ast.Name):
        value = static_string(stmt.value, env)
        if value is not None:
            env[stmt.targets[0].id] = value
        if stmt.targets[0].id == "args" and isinstance(stmt.value, ast.List):
            args_tokens = tokens_from(stmt.value.elts)
    if isinstance(stmt, ast.AugAssign) and isinstance(stmt.target, ast.Name) and stmt.target.id == "args" and isinstance(stmt.op, ast.Add):
        if isinstance(stmt.value, ast.List):
            args_tokens += tokens_from(stmt.value.elts)
        elif isinstance(stmt.value, (ast.ListComp, ast.GeneratorExp)):
            args_tokens.append(static_string(stmt.value.elt, env))
    if isinstance(stmt, ast.Expr) and isinstance(stmt.value, ast.Call) and isinstance(stmt.value.func, ast.Name) and stmt.value.func.id == "orbit":
        remember(stmt.value, stmt.lineno)
    for child in ast.iter_child_nodes(stmt):
        if isinstance(child, ast.stmt):
            handle(child)
        elif isinstance(child, ast.expr):
            for call in ast.walk(child):
                if isinstance(call, ast.Call) and isinstance(call.func, ast.Name) and call.func.id == "orbit":
                    remember(call, getattr(call, "lineno", stmt.lineno))

field_problems = []

def analyze_fields(tree):
    global env, args_tokens
    env = {}
    args_tokens = []
    typed = {}
    functions = {}
    calling = set()

    def record(line, message):
        field_problems.append((line, message))

    def merge(left, right):
        if left == right or right[0] == "unk":
            return left
        if left[0] == "unk" or left[0] == "scalar":
            return right
        if right[0] == "scalar":
            return left
        return ("unk",)

    def response_type(command):
        info = responses.get(command) if isinstance(command, str) else None
        if not isinstance(info, dict) or not isinstance(info.get("schema"), str):
            return ("unmapped", command or "")
        listed = info.get("list")
        if isinstance(listed, str) and listed:
            return ("env", info["schema"], listed)
        return ("obj", info["schema"])

    def read_field(value, key, line):
        kind = value[0]
        if kind == "open":
            return ("open",)
        if kind == "unmapped":
            record(line, f"reads {key} from unmapped {value[1]}")
            return ("unk",)
        if kind == "env":
            schema, list_key = value[1], value[2]
            if key == list_key:
                return ("list", schema)
            if key in ("data", "meta", "request_id"):
                return ("unk",)
            record(line, f"reads {key}, which the {schema} list response does not use")
            return ("unk",)
        if kind == "list":
            record(line, f"reads {key} from a list of {value[1]}")
            return ("unk",)
        if kind != "obj":
            return ("unk",)
        schema = value[1]
        if key in schema_fields.get(schema, []):
            spec = schema_kinds.get(f"{schema}.{key}", {"kind": "scalar"})
            spec_kind = spec.get("kind") if isinstance(spec, dict) else None
            nested = spec.get("schema") if isinstance(spec, dict) else None
            if spec_kind == "open":
                return ("open",)
            if spec_kind == "object" and isinstance(nested, str):
                return ("obj", nested)
            if spec_kind == "array":
                return ("list", nested) if isinstance(nested, str) else ("unk",)
            return ("scalar",)
        if schema in open_schemas:
            return ("open",)
        record(line, f"reads {key}, which {schema} does not define")
        return ("unk",)

    def bind_target(target, value, types):
        if isinstance(target, ast.Name):
            types[target.id] = value
        elif isinstance(target, (ast.Tuple, ast.List)):
            parts = value[1] if value[0] == "tuple" else None
            for index, elt in enumerate(target.elts):
                bind_target(elt, parts[index] if parts is not None and index < len(parts) else ("unk",), types)

    def element_type(value):
        if value[0] == "list" and isinstance(value[1], str):
            return ("obj", value[1])
        if value[0] == "tuplist":
            return ("tuple", value[1])
        return ("unk",)

    def eval_expr(node, types):
        if isinstance(node, ast.Constant):
            return ("scalar",)
        if isinstance(node, ast.Name):
            return types.get(node.id, ("unk",))
        if isinstance(node, ast.Subscript):
            value = eval_expr(node.value, types)
            if isinstance(node.slice, ast.Constant) and isinstance(node.slice.value, str):
                return read_field(value, node.slice.value, node.lineno)
            if not isinstance(node.slice, ast.Constant):
                eval_expr(node.slice, types)
            if value[0] == "list" and isinstance(value[1], str):
                return ("obj", value[1])
            return ("unk",)
        if isinstance(node, ast.Call):
            return eval_call(node, types)
        if isinstance(node, ast.IfExp):
            return merge(eval_expr(node.body, types), eval_expr(node.orelse, types))
        if isinstance(node, ast.Attribute):
            eval_expr(node.value, types)
            return ("unk",)
        if isinstance(node, (ast.ListComp, ast.GeneratorExp, ast.SetComp, ast.DictComp)):
            return eval_comp(node, types)
        if isinstance(node, (ast.Tuple, ast.List)):
            elements = [eval_expr(elt, types) for elt in node.elts]
            if isinstance(node, ast.Tuple):
                return ("tuple", elements)
            if elements and all(item[0] == "tuple" and len(item[1]) == len(elements[0][1]) for item in elements):
                merged = []
                for index in range(len(elements[0][1])):
                    acc = elements[0][1][index]
                    for item in elements[1:]:
                        acc = merge(acc, item[1][index])
                    merged.append(acc)
                return ("tuplist", merged)
            if elements and all(item[0] == "obj" and item[1] == elements[0][1] for item in elements):
                return ("list", elements[0][1])
            return ("unk",)
        if isinstance(node, ast.Compare):
            eval_expr(node.left, types)
            for item in node.comparators:
                eval_expr(item, types)
            return ("scalar",)
        if isinstance(node, ast.BoolOp):
            for item in node.values:
                eval_expr(item, types)
            return ("scalar",)
        if isinstance(node, ast.BinOp):
            eval_expr(node.left, types)
            eval_expr(node.right, types)
            return ("scalar",)
        if isinstance(node, ast.UnaryOp):
            return eval_expr(node.operand, types)
        if isinstance(node, ast.Starred):
            return eval_expr(node.value, types)
        for child in ast.iter_child_nodes(node):
            if isinstance(child, ast.expr):
                eval_expr(child, types)
        return ("unk",)

    def eval_comp(node, types):
        local = dict(types)
        for generator in node.generators:
            bind_target(generator.target, element_type(eval_expr(generator.iter, local)), local)
            for test in generator.ifs:
                eval_expr(test, local)
        if isinstance(node, ast.DictComp):
            eval_expr(node.key, local)
            eval_expr(node.value, local)
            return ("unk",)
        element = eval_expr(node.elt, local)
        if isinstance(node, ast.ListComp) and element[0] == "obj":
            return ("list", element[1])
        if isinstance(node, ast.ListComp) and element[0] == "tuple":
            return ("tuplist", element[1])
        return ("unk",)

    def orbit_command(node):
        if node.args and isinstance(node.args[0], ast.Starred) and isinstance(node.args[0].value, ast.Name) and node.args[0].value.id == "args":
            token = args_tokens[0] if args_tokens else None
            return token if isinstance(token, str) else None
        if not node.args:
            return None
        command = static_string(node.args[0], env)
        return command if isinstance(command, str) else None

    def eval_call(node, types):
        func = node.func
        if isinstance(func, ast.Attribute) and func.attr == "get":
            value = eval_expr(func.value, types)
            key = node.args[0].value if node.args and isinstance(node.args[0], ast.Constant) and isinstance(node.args[0].value, str) else None
            for arg in node.args[1:]:
                eval_expr(arg, types)
            for keyword in node.keywords:
                if keyword.value is not None:
                    eval_expr(keyword.value, types)
            if key is None:
                return ("unk",)
            return read_field(value, key, node.lineno)
        if isinstance(func, ast.Name) and func.id == "orbit":
            for arg in node.args:
                eval_expr(arg, types)
            return response_type(orbit_command(node))
        if isinstance(func, ast.Name) and func.id in functions:
            return call_function(func.id, node, types)
        if not isinstance(func, ast.Name):
            eval_expr(func, types)
        for arg in node.args:
            eval_expr(arg, types)
        for keyword in node.keywords:
            if keyword.value is not None:
                eval_expr(keyword.value, types)
        return ("unk",)

    def call_function(name, node, types):
        if name in calling:
            for arg in node.args:
                eval_expr(arg, types)
            return ("unk",)
        function = functions[name]
        calling.add(name)
        local = dict(types)
        params = [arg.arg for arg in function.args.args]
        for param in params:
            local[param] = ("unk",)
        for param, arg in zip(params, node.args):
            local[param] = eval_expr(arg, types)
        for arg in node.args[len(params):]:
            eval_expr(arg, types)
        returned = walk(function.body, local)
        calling.remove(name)
        return returned

    def note_args(stmt):
        global args_tokens
        if isinstance(stmt, ast.Assign) and len(stmt.targets) == 1 and isinstance(stmt.targets[0], ast.Name):
            name = stmt.targets[0].id
            value = static_string(stmt.value, env)
            if value is not None:
                env[name] = value
            if name == "args" and isinstance(stmt.value, ast.List):
                args_tokens = tokens_from(stmt.value.elts)
            return name
        if isinstance(stmt, ast.AugAssign) and isinstance(stmt.target, ast.Name) and stmt.target.id == "args" and isinstance(stmt.op, ast.Add):
            if isinstance(stmt.value, ast.List):
                args_tokens += tokens_from(stmt.value.elts)
            elif isinstance(stmt.value, (ast.ListComp, ast.GeneratorExp)):
                args_tokens.append(static_string(stmt.value.elt, env))
        return None

    def walk(stmts, types):
        returned = ("unk",)
        for stmt in stmts:
            if isinstance(stmt, (ast.FunctionDef, ast.AsyncFunctionDef)):
                functions[stmt.name] = stmt
                continue
            if isinstance(stmt, ast.ClassDef):
                continue
            assigned = note_args(stmt)
            if isinstance(stmt, ast.Assign):
                value = eval_expr(stmt.value, types)
                if assigned is not None:
                    types[assigned] = value
                else:
                    for target in stmt.targets:
                        bind_target(target, value, types)
                continue
            if isinstance(stmt, ast.AnnAssign) and stmt.value is not None:
                value = eval_expr(stmt.value, types)
                bind_target(stmt.target, value, types)
                continue
            if isinstance(stmt, ast.AugAssign):
                eval_expr(stmt.value, types)
                continue
            if isinstance(stmt, ast.For):
                bind_target(stmt.target, element_type(eval_expr(stmt.iter, types)), types)
                returned = merge(returned, walk(stmt.body, types))
                returned = merge(returned, walk(stmt.orelse, types))
                continue
            if isinstance(stmt, ast.If):
                eval_expr(stmt.test, types)
                returned = merge(returned, walk(stmt.body, types))
                returned = merge(returned, walk(stmt.orelse, types))
                continue
            if isinstance(stmt, ast.While):
                eval_expr(stmt.test, types)
                returned = merge(returned, walk(stmt.body, types))
                continue
            if isinstance(stmt, ast.Try):
                returned = merge(returned, walk(stmt.body, types))
                for handler in stmt.handlers:
                    returned = merge(returned, walk(handler.body, types))
                returned = merge(returned, walk(stmt.orelse, types))
                returned = merge(returned, walk(stmt.finalbody, types))
                continue
            if isinstance(stmt, ast.Return):
                if stmt.value is not None:
                    returned = merge(returned, eval_expr(stmt.value, types))
                continue
            if isinstance(stmt, ast.Expr):
                eval_expr(stmt.value, types)
                continue
            for child in ast.iter_child_nodes(stmt):
                if isinstance(child, ast.stmt):
                    returned = merge(returned, walk([child], types))
                elif isinstance(child, ast.expr):
                    eval_expr(child, types)
        return returned

    walk(tree.body, typed)

for stmt in tree.body:
    handle(stmt)
try:
    analyze_fields(tree)
except Exception as error:
    print(json.dumps({"error": f"field check: {type(error).__name__}: {error}", "calls": calls, "unparsed": unparsed, "fields": []}))
    sys.exit(0)
seen_fields = set()
fields = []
for line, message in field_problems:
    item = (line, message)
    if item in seen_fields:
        continue
    seen_fields.add(item)
    fields.append({"line": line, "message": message})
print(json.dumps({"error": None, "calls": calls, "unparsed": unparsed, "fields": fields}))
PYTHON;
}
