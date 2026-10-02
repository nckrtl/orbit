<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'mcp-schema-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.71',
        'wireguard_ip' => '10.44.0.71',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->postJson('/api/v1/extensions/tasks/enable')->assertOk();
    $this->postJson('/api/v1/extensions/proxycli/enable')->assertOk();
});

describe('MCP tool schemas', function (): void {
    it('empty object schemas stay objects', function (): void {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => (object) [],
        ]);
        $response->assertOk();
        $document = mcp_schema_document($response);
        $tools = collect($document->result->tools)->keyBy('name');

        foreach (['tasks-definition-create', 'tasks-definition-update'] as $name) {
            $tool = $tools->get($name);
            expect($tool)->toBeInstanceOf(stdClass::class);
            $schema = $tool->inputSchema->properties->parameters->items->properties->default;
            expect($schema)->toBeInstanceOf(stdClass::class)
                ->and(json_encode($schema, JSON_THROW_ON_ERROR))->toBe('{}');
        }
    });

    it('serves every generated input schema unchanged through tools/list', function (): void {
        $generated = json_decode((string) file_get_contents(resource_path('mcp/tools.json')), associative: false, flags: JSON_THROW_ON_ERROR);
        $tools = [];
        $cursor = null;

        do {
            $response = $this->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => $cursor === null ? (object) [] : ['cursor' => $cursor],
            ]);
            $response->assertOk();
            $document = mcp_schema_document($response);
            $tools = [...$tools, ...$document->result->tools];
            $cursor = $document->result->nextCursor ?? null;
        } while (is_string($cursor));

        $served = collect($tools)->keyBy('name');
        expect($served->keys()->all())->toEqualCanonicalizing(array_column($generated->tools, 'name'));

        foreach ($generated->tools as $tool) {
            expect(json_encode($served->get($tool->name)->inputSchema, JSON_THROW_ON_ERROR))
                ->toBe(json_encode($tool->input_schema, JSON_THROW_ON_ERROR), $tool->name);
        }
    });

    it('serves every generated input schema unchanged through search_tools', function (): void {
        $generated = json_decode((string) file_get_contents(resource_path('mcp/tools.json')), associative: false, flags: JSON_THROW_ON_ERROR);

        foreach ($generated->tools as $tool) {
            $response = $this->postJson('/mcp/search', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'search_tools',
                    'arguments' => ['query' => $tool->name, 'limit' => 1],
                ],
            ]);
            $response->assertOk();
            $document = mcp_schema_document($response);
            expect($document->result->isError)->toBeFalse();
            $found = json_decode($document->result->content[0]->text, associative: false, flags: JSON_THROW_ON_ERROR);

            expect($found->tools)->toHaveCount(1);
            expect($found->tools[0]->name)->toBe($tool->name);
            expect(json_encode($found->tools[0]->inputSchema, JSON_THROW_ON_ERROR))
                ->toBe(json_encode($tool->input_schema, JSON_THROW_ON_ERROR), $tool->name);
        }
    });
});

function mcp_schema_document(TestResponse $response): stdClass
{
    $content = $response->getContent();

    if ($response->baseResponse instanceof StreamedResponse) {
        preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $matches);
        $content = end($matches[1]);
    }

    $document = json_decode($content, associative: false, flags: JSON_THROW_ON_ERROR);
    expect($document)->toBeInstanceOf(stdClass::class);

    return $document;
}
