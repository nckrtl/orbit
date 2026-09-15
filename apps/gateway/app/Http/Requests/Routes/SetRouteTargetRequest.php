<?php

declare(strict_types=1);

namespace App\Http\Requests\Routes;

use App\Data\Routes\RouteTargetDispositionData;
use App\Data\Routes\SetRouteTargetsData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\AppInstance;
use App\Models\Route;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class SetRouteTargetRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'app_instance_id' => ['required_without:targets', 'prohibits:targets', 'integer', Rule::exists(new AppInstance()->getTable(), 'id')],
            'targets' => ['required_without:app_instance_id', 'array'],
            'targets.*' => ['integer', Rule::exists(new AppInstance()->getTable(), 'id')],
            'dispositions' => ['array'],
            'dispositions.*.app_instance_id' => ['required', 'integer', Rule::exists(new AppInstance()->getTable(), 'id')],
            'dispositions.*.route_id' => ['integer', Rule::exists(new Route()->getTable(), 'id')],
            'dispositions.*.remove' => ['boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['app_instance_id', 'targets', 'dispositions'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function isTargetSet(): bool
    {
        return array_key_exists('targets', $this->validated());
    }

    public function appInstanceId(): int
    {
        return (int) $this->validated('app_instance_id');
    }

    public function payload(): SetRouteTargetsData
    {
        $dispositions = [];

        foreach ($this->validated('dispositions') ?? [] as $disposition) {
            if (! is_array($disposition)) {
                continue;
            }

            $dispositions[] = new RouteTargetDispositionData(
                appInstanceId: (int) $disposition['app_instance_id'],
                routeId: isset($disposition['route_id']) ? (int) $disposition['route_id'] : null,
                remove: ($disposition['remove'] ?? false) === true,
            );
        }

        /** @var list<int> $targets */
        $targets = array_map(intval(...), $this->validated('targets') ?? []);

        return new SetRouteTargetsData($targets, $dispositions);
    }
}
