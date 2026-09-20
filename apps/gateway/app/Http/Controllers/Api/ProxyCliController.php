<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ProxyCli\DisableProxyCliAction;
use App\Actions\ProxyCli\EnableProxyCliAction;
use App\Actions\ProxyCli\ListProxyCliProvidersAction;
use App\Actions\ProxyCli\ShowProxyCliProviderAction;
use App\Actions\ProxyCli\ShowProxyCliStatusAction;
use App\Actions\ProxyCli\UpdateProxyCliAccountAction;
use App\Data\ProxyCli\ProxyCliStatusData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProxyCli\EnableProxyCliRequest;
use App\Http\Requests\ProxyCli\UpdateProxyCliAccountRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class ProxyCliController extends Controller
{
    public function store(EnableProxyCliRequest $request, EnableProxyCliAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute($request->payload()), 201);
    }

    public function destroy(Request $request, DisableProxyCliAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute());
    }

    public function status(Request $request, ShowProxyCliStatusAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute());
    }

    public function index(Request $request, ListProxyCliProvidersAction $action): JsonResponse
    {
        return $this->payload($request, $action->execute());
    }

    public function show(Request $request, string $provider, ShowProxyCliProviderAction $action): JsonResponse
    {
        return $this->payload($request, $action->execute($provider));
    }

    public function update(UpdateProxyCliAccountRequest $request, string $account, UpdateProxyCliAccountAction $action): JsonResponse
    {
        return $this->payload($request, $action->execute($account, $request->disabled()));
    }

    private function statusResponse(Request $request, ProxyCliStatusData $result, int $status = 200): JsonResponse
    {
        return $this->payload($request, $result->toArray(), $status);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function payload(Request $request, array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], $status);
    }
}
