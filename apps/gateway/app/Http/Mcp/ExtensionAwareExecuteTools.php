<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Tasks\TaskDefinitionJson;
use Illuminate\Validation\ValidationException;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\ExecuteTools;
use Laravel\Mcp\Server\Tools\ToolSearch;

final class ExtensionAwareExecuteTools extends ExecuteTools
{
    public function __construct(ToolSearch $catalog, int $maxToolCalls)
    {
        parent::__construct($catalog, $maxToolCalls);
    }

    /** @return array<int, Response> */
    #[\Override]
    public function handle(Request $request): array
    {
        // Keep Laravel MCP's batch validation and call limit ahead of all invocation.
        $request->validate([
            'calls' => ['required', 'array', 'min:1', 'max:'.$this->maxToolCalls],
            'calls.*' => ['array:name,arguments'],
            'calls.*.name' => ['required', 'string', 'max:255'],
            'calls.*.arguments' => ['array'],
        ]);

        $calls = $request->get('calls');
        if (! is_array($calls) || ! array_is_list($calls)) {
            throw ValidationException::withMessages(['calls' => 'The calls field must be a list.']);
        }
        foreach ($calls as $index => $call) {
            $arguments = $call['arguments'] ?? [];
            if ($arguments !== [] && array_is_list($arguments)) {
                throw ValidationException::withMessages(["calls.{$index}.arguments" => 'The arguments field must be an object.']);
            }
        }

        $definitions = ToolManifest::default()->definitions();
        $extensions = app(ExtensionStore::class);
        $preservedCalls = $this->preservedCalls(request()->getContent());
        $results = [];
        $notifications = [];
        $maxOutputBytes = config('mcp.tool_search.max_output_bytes', 65_536);
        $maxOutputBytes = is_int($maxOutputBytes) ? max(256, $maxOutputBytes) : 65_536;

        foreach ($calls as $index => $call) {
            if (is_array($call)) {
                $call = $this->callKeepingObjects($call, $preservedCalls[$index] ?? null);
            }
            $disabledExtension = null;
            foreach ($definitions as $definition) {
                if ($call['name'] === $definition->name && $definition->extension !== null
                    && ! $extensions->enabled($definition->extension)) {
                    $disabledExtension = $definition->extension;
                    break;
                }
            }

            $lastResult = null;
            if ($disabledExtension !== null) {
                $error = json_encode([
                    'status' => 409,
                    'error' => [
                        'code' => 'extension.disabled',
                        'message' => "The {$disabledExtension} extension is disabled.",
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $lastResult = [
                    'name' => $call['name'],
                    'isError' => true,
                    'content' => [['type' => 'text', 'text' => $error === false ? 'Extension disabled.' : $error]],
                ];
            } else {
                $responses = $this->catalog->execute([$call], $request);
                foreach ($responses as $response) {
                    if ($response->isNotification()) {
                        $notifications[] = $response;

                        continue;
                    }

                    $content = $response->content()->toArray();
                    $text = $content['text'] ?? null;
                    if (! is_string($text)) {
                        continue;
                    }
                    try {
                        $payload = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        continue;
                    }
                    if (is_array($payload) && is_array($payload['error'] ?? null)
                        && ($payload['error']['kind'] ?? null) === 'OutputLimitExceeded') {
                        return [...$notifications, $this->catalog->response([
                            'ok' => false,
                            'error' => [
                                'kind' => 'OutputLimitExceeded',
                                'message' => "The tool output exceeded {$maxOutputBytes} bytes.",
                            ],
                            'completedToolCalls' => count($results) + 1,
                            'attemptedToolCalls' => $index + 1,
                        ], true)];
                    }
                    if (is_array($payload) && is_array($payload['results'] ?? null)) {
                        $lastResult = $payload['results'][0] ?? null;
                        if (is_array($lastResult)) {
                            break;
                        }
                    }
                }
            }

            if (! is_array($lastResult)) {
                $lastResult = [
                    'name' => $call['name'],
                    'content' => [['type' => 'text', 'text' => 'Tool execution returned no result.']],
                    'isError' => true,
                ];
            }
            $results[] = $lastResult;

            $size = strlen((string) json_encode(
                ['ok' => true, 'results' => []],
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            foreach ($results as $entry) {
                $size += strlen((string) json_encode(
                    $entry,
                    JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )) + 1;
            }
            if ($size > $maxOutputBytes) {
                return [...$notifications, $this->catalog->response([
                    'ok' => false,
                    'error' => [
                        'kind' => 'OutputLimitExceeded',
                        'message' => "The tool output exceeded {$maxOutputBytes} bytes.",
                    ],
                    'completedToolCalls' => count($results),
                    'attemptedToolCalls' => $index + 1,
                ], true)];
            }

            if (($lastResult['isError'] ?? false) === true) {
                return [...$notifications, $this->catalog->response(['ok' => false, 'results' => $results], true)];
            }
        }

        return [...$notifications, $this->catalog->response(['ok' => true, 'results' => $results])];
    }

    /**
     * Laravel MCP decodes the JSON-RPC body associatively, which turns `{}` into a list.
     * Each catalog execute receives one call, so the synthetic request id cannot tell the calls apart.
     * The matching raw call is the one at the same index.
     *
     * @return list<mixed>
     */
    private function preservedCalls(string $raw): array
    {
        $decoded = TaskDefinitionJson::decode($raw);
        $params = is_array($decoded) ? ($decoded['params'] ?? null) : null;
        $arguments = is_array($params) ? ($params['arguments'] ?? null) : null;
        $calls = is_array($arguments) ? ($arguments['calls'] ?? null) : null;

        return is_array($calls) && array_is_list($calls) ? $calls : [];
    }

    /**
     * @param  array<mixed, mixed>  $call
     * @return array<mixed, mixed>
     */
    private function callKeepingObjects(array $call, mixed $preserved): array
    {
        if (! is_array($preserved) || ($preserved['name'] ?? null) !== ($call['name'] ?? null)) {
            return $call;
        }

        $arguments = $preserved['arguments'] ?? null;

        if (! is_array($arguments) || array_is_list($arguments)) {
            return $call;
        }

        $kept = [];

        foreach ($arguments as $key => $value) {
            if (is_string($key)) {
                $kept[$key] = $value;
            }
        }

        $call['arguments'] = $kept;

        return $call;
    }
}
