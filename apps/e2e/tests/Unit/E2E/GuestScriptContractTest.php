<?php

declare(strict_types=1);

/**
 * Guest scripts call the Orbit CLI and read its JSON. This test fails when a
 * call uses a command or option that is not in the CLI signature, or when a
 * snippet reads a field the OpenAPI schema does not define.
 *
 * Keys read from the script's own state file are the only allowlist. The list
 * envelope is the CLI collection (`instances`, `nodes`, `projects`, `routes`,
 * `clusters`) plus `request_id`, and the OpenAPI list fields `data` and `meta`.
 */
it('matches guest orbit commands and options to the CLI signatures', function (): void {
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
        foreach ([...guest_script_orbit_invocations($source), ...$python['calls']] as $call) {
            $location = basename($path).':'.$call['line'].' '.$call['command'];
            if (! isset($commands[$call['command']])) {
                $problems[] = "{$location} is not a CLI command";

                continue;
            }
            foreach ($call['options'] as $option) {
                if (! in_array($option, $commands[$call['command']], true)) {
                    $problems[] = "{$location} has no --{$option} option";
                }
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
 * @return array<string, list<string>>
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
        [$name, $options] = guest_script_signature_options($signature);
        expect($commands)->not->toHaveKey($name);
        $commands[$name] = $options;
    }

    return $commands;
}

/**
 * @return array{0: string, 1: list<string>}
 */
function guest_script_signature_options(string $signature): array
{
    $name = preg_split('/\s+/', trim($signature))[0] ?? '';
    $options = [];
    preg_match_all('/\{\s*(.*?)\s*\}/s', $signature, $tokens);
    foreach ($tokens[1] as $token) {
        $token = trim(preg_replace('/\s+/', ' ', $token) ?? $token);
        if (! str_starts_with($token, '--')) {
            continue;
        }
        $spec = preg_replace('/^-{2,}/', '', $token) ?? $token;
        $spec = explode(' : ', $spec, 2)[0];
        if (str_contains($spec, '|')) {
            $spec = explode('|', $spec, 2)[1];
        }
        $option = explode('=', explode('*', $spec, 2)[0], 2)[0];
        $options[] = trim($option);
    }

    return [$name, $options];
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
 * @return list<array{line: int, command: string, options: list<string>}>
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
 * @return array{line: int, command: string, options: list<string>}
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
            $words[] = $word;
        }
    }
    $command = $words[0] ?? '';
    if (preg_match('/\A[a-z][a-z0-9]*(?::[a-z0-9-]+)*\z/', $command) !== 1) {
        $command = '';
    }
    $options = [];
    foreach (array_slice($words, 1) as $word) {
        if (! str_starts_with($word, '--')) {
            continue;
        }
        $option = explode('=', substr($word, 2), 2)[0];
        if ($option !== '') {
            $options[] = $option;
        }
    }

    return ['line' => $line, 'command' => $command, 'options' => $options];
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
 * @return array{error: ?string, calls: list<array{line: int, command: string, options: list<string>}>, unparsed: list<string>}
 */
function guest_script_python_calls(string $source): array
{
    $empty = ['error' => null, 'calls' => [], 'unparsed' => []];
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
        return ['error' => 'python3 did not start', 'calls' => [], 'unparsed' => []];
    }
    fwrite($pipes[0], $heredoc[1]);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode(is_string($stdout) ? $stdout : '', true);
    if ($exit !== 0 || ! is_array($decoded)) {
        $message = trim(is_string($stderr) ? $stderr : '');

        return ['error' => $message !== '' ? $message : 'python3 did not parse the guest script', 'calls' => [], 'unparsed' => []];
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
        ];
    }

    return [
        'error' => is_string($decoded['error'] ?? null) ? $decoded['error'] : null,
        'calls' => $calls,
        'unparsed' => array_values(array_map(strval(...), is_array($decoded['unparsed'] ?? null) ? $decoded['unparsed'] : [])),
    ];
}

function guest_script_python_parser(): string
{
    return <<<'PYTHON'
import ast, json, re, sys
source = sys.stdin.read()
try:
    tree = ast.parse(source)
except SyntaxError as error:
    print(json.dumps({"error": f"line {error.lineno}: {error.msg}", "calls": [], "unparsed": []}))
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
    starred_args = any(isinstance(arg, ast.Starred) and isinstance(arg.value, ast.Name) and arg.value.id == "args" for arg in node.args)
    if starred_args:
        if args_tokens:
            command = args_tokens[0]
        for token in args_tokens[1:]:
            name = option_name(token)
            if name:
                options.append(name)
    else:
        if node.args:
            command = static_string(node.args[0], env)
        for arg in node.args[1:]:
            if isinstance(arg, ast.Starred) and isinstance(arg.value, (ast.GeneratorExp, ast.ListComp)):
                name = option_name(static_string(arg.value.elt, env))
                text = expression_text(arg)
            else:
                name = option_name(static_string(arg, env))
                text = expression_text(arg)
            if name:
                options.append(name)
            elif "--" in text:
                unparsed.append(f"line {lineno}: unparsed orbit argument")
    if isinstance(command, str) and re.fullmatch(r"[a-z][a-z0-9]*(?::[a-z0-9-]+)*", command):
        calls.append({"line": lineno, "command": command, "options": options})

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

for stmt in tree.body:
    handle(stmt)
print(json.dumps({"error": None, "calls": calls, "unparsed": unparsed}))
PYTHON;
}
