<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\ProxyCli\ProxyCliState;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

final class FakeSandboxModelProxy
{
    /** @var list<string> */
    public array $keys = [];

    public bool $available = true;

    public function install(): void
    {
        config(['compute.model_proxy.enabled' => true]);
        app(ProxyCliState::class)->enable(1, 'proof', 'http://127.0.0.1:28317', 'management-proof-secret', 'read', 'control', 8787);
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake(['http://127.0.0.1:28317/*' => $this->respond(...)]);
    }

    public function respond(Request $request): PromiseInterface
    {
        if (! $this->available) {
            return Http::response([], 503);
        }
        $token = substr($request->header('Authorization')[0] ?? '', 7);
        if (str_ends_with($request->url(), '/v1/models')) {
            return Http::response(['data' => []], $token !== '' && in_array($token, $this->keys, true) ? 200 : 401);
        }
        if ($token !== 'management-proof-secret') {
            return Http::response([], 401);
        }
        if ($request->method() === 'GET') {
            return Http::response(['api-keys' => $this->keys]);
        }
        if ($request->method() !== 'PATCH' || ! is_string($request['old']) || ! is_string($request['new'])) {
            return Http::response([], 400);
        }
        $index = array_search($request['old'], $this->keys, true);
        if ($index === false) {
            $this->keys[] = $request['new'];
        } else {
            $this->keys[$index] = $request['new'];
        }

        return Http::response(['status' => 'ok']);
    }
}
