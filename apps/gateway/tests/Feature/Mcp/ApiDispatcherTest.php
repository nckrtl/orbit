<?php

declare(strict_types=1);

use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ToolDefinition;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request without content reads php://input. Under PHP-FPM that is the MCP caller's JSON-RPC envelope, so a
 * bodiless internal request must carry empty content instead.
 */
it('gives a bodiless internal request empty content instead of the caller body', function (string $method): void {
    $kernel = new class implements Kernel
    {
        public ?Request $request = null;

        public function bootstrap(): void {}

        public function handle($request): Response
        {
            $this->request = $request;

            return new Response('{}', 200);
        }

        public function terminate($request, $response): void {}

        public function getApplication(): never
        {
            throw new LogicException('Not used.');
        }
    };
    $definition = new ToolDefinition(
        name: 'tasks-subtask-destroy',
        title: 'Destroy a subtask',
        description: 'Deletes a subtask.',
        method: $method,
        path: '/api/v1/task-groups/{group}/tasks/{task}',
        pathInputs: ['group', 'task'],
        queryInputs: [],
        streams: false,
        inputSchema: ['type' => 'object'],
    );

    new ApiDispatcher(app(), $kernel)->dispatch($definition, ['group' => 4, 'task' => 7], Request::create('/mcp', 'POST'));

    expect($kernel->request?->getPathInfo())->toBe('/api/v1/task-groups/4/tasks/7')
        ->and(new ReflectionProperty(SymfonyRequest::class, 'content')->getValue($kernel->request))->toBe('');
})->with(['DELETE', 'GET']);
