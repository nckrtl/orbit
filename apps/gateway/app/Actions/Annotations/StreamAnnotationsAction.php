<?php

declare(strict_types=1);

namespace App\Actions\Annotations;

use App\Data\Annotations\AnnotationData;
use App\Models\Annotation;
use App\Models\AppInstance;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class StreamAnnotationsAction
{
    public function execute(AppInstance $instance, int $after): StreamedResponse
    {
        return response()->stream(function () use ($instance, $after): \Generator {
            yield "retry: 2000\n\n";
            foreach (DB::table('annotation_events')->where('app_instance_id', $instance->id)->where('id', '>', $after)->orderBy('id')->limit(100)->get() as $event) {
                yield 'id: '.$event->id."\nevent: annotation.updated\ndata: ".$event->payload."\n\n";
            }
            // Snapshot and cursor are read together: no change can fall between the two.
            [$cursor, $annotations] = DB::transaction(fn (): array => [(int) DB::table('annotation_events')->max('id'), Annotation::query()->with('task')->where('app_instance_id', $instance->id)->get()->map(static fn (Annotation $a): array => AnnotationData::fromModel($a)->annotation)->all()]);
            yield 'id: '.$cursor."\nevent: snapshot\ndata: ".json_encode(['annotations' => $annotations], JSON_THROW_ON_ERROR)."\n\n";
            // Finish promptly so PHP workers remain available to normal API requests.
            // EventSource reconnects using the saved cursor and the retry interval above.

        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store', 'X-Accel-Buffering' => 'no']);
    }
}
