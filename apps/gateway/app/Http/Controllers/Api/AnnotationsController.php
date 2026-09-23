<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Annotations\AnnotationStoreAction;
use App\Actions\Annotations\StreamAnnotationsAction;
use App\Data\Annotations\AnnotationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Annotations\RetryAnnotationRequest;
use App\Http\Requests\Annotations\StoreAnnotationRequest;
use App\Http\Requests\Annotations\StreamAnnotationsRequest;
use App\Http\Requests\Annotations\UpdateAnnotationRequest;
use App\Models\Annotation;
use App\Models\AppInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[RequiresNodeAccess(ServingNode::InstanceOwning)]
final class AnnotationsController extends Controller
{
    public function index(Request $request, AppInstance $instance): JsonResponse
    {
        return response()->json(['data' => Annotation::query()->with('task')->where('app_instance_id', $instance->id)->orderBy('created_at')->get()->map(static fn (Annotation $a): array => AnnotationData::fromModel($a)->annotation)->all(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function store(StoreAnnotationRequest $request, AppInstance $instance, AnnotationStoreAction $action): JsonResponse
    {
        return response()->json(['data' => AnnotationData::fromModel($action->create($instance, $request->payload()))->annotation, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 201);
    }

    public function update(UpdateAnnotationRequest $request, AppInstance $instance, Annotation $annotation, AnnotationStoreAction $action): JsonResponse
    {
        abort_unless($annotation->app_instance_id === $instance->id, 404);

        return response()->json(['data' => AnnotationData::fromModel($action->transition($annotation, $request->string('status')->toString(), $request->filled('summary') ? $request->string('summary')->toString() : null))->annotation, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function retry(RetryAnnotationRequest $request, AppInstance $instance, Annotation $annotation, AnnotationStoreAction $action): JsonResponse
    {
        abort_unless($annotation->app_instance_id === $instance->id, 404);

        return response()->json(['data' => AnnotationData::fromModel($action->retry($annotation, $request->filled('threadId') ? $request->string('threadId')->toString() : null))->annotation, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function events(StreamAnnotationsRequest $request, AppInstance $instance, StreamAnnotationsAction $action): StreamedResponse
    {
        return $action->execute($instance, (int) $request->validated('after', 0));
    }
}
