<?php

declare(strict_types=1);

namespace App\Http\Requests\Routes;

use App\Data\Routes\RouteTargetDispositionData;
use App\Data\Routes\SetRouteTargetsData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Instance;
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
            'instance_id' => ['required_without:targets', 'prohibits:targets', 'integer', Rule::exists(new Instance()->getTable(), 'id')],
            'targets' => ['required_without:instance_id', 'array'],
            'targets.*' => ['integer', Rule::exists(new Instance()->getTable(), 'id')],
            'dispositions' => ['array'],
            'dispositions.*.instance_id' => ['required', 'integer', Rule::exists(new Instance()->getTable(), 'id')],
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
                ['instance_id', 'targets', 'dispositions'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function isTargetSet(): bool
    {
        return array_key_exists('targets', $this->validated());
    }

    public function instanceId(): int
    {
        return self::integerValue($this->validated('instance_id'));
    }

    public function payload(): SetRouteTargetsData
    {
        $dispositions = [];
        $validatedDispositions = $this->validated('dispositions');

        foreach (is_array($validatedDispositions) ? $validatedDispositions : [] as $disposition) {
            if (! is_array($disposition)) {
                continue;
            }

            $instanceId = self::integerValue($disposition['instance_id'] ?? null);
            $routeId = array_key_exists('route_id', $disposition)
                ? self::integerValue($disposition['route_id'])
                : null;

            $dispositions[] = new RouteTargetDispositionData(
                instanceId: $instanceId,
                routeId: $routeId,
                remove: ($disposition['remove'] ?? false) === true,
            );
        }

        $validatedTargets = $this->validated('targets');
        $targets = is_array($validatedTargets)
            ? array_values(array_map(self::integerValue(...), $validatedTargets))
            : [];

        return new SetRouteTargetsData($targets, $dispositions);
    }

    private static function integerValue(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

        if (! is_int($integer)) {
            throw new UnexpectedValueException('A route target identifier must be an integer.');
        }

        return $integer;
    }
}
