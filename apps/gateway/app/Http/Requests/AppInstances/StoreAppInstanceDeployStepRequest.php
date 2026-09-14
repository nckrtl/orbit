<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Domain\AppInstances\Deployment\DeploymentPhase;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnexpectedValueException;

final class StoreAppInstanceDeployStepRequest extends FormRequest
{
    private ?DeploymentStep $step = null;

    private ?string $before = null;

    private ?string $after = null;

    /** @return array{} */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $payload = app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['name', 'command', 'phase', 'timeout_seconds', 'before', 'after'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }

        if (! is_string($payload['name'] ?? null) || ! is_string($payload['command'] ?? null)) {
            throw ValidationException::withMessages(['body' => ['Each deploy step requires a name and command.']]);
        }

        if (array_key_exists('phase', $payload) && ! is_string($payload['phase'])) {
            throw ValidationException::withMessages(['body' => ['The phase field must be a string.']]);
        }

        if (array_key_exists('timeout_seconds', $payload) && ! is_int($payload['timeout_seconds'])) {
            throw ValidationException::withMessages(['body' => ['The timeout must be an integer.']]);
        }

        if (array_key_exists('before', $payload) && ! is_string($payload['before'])) {
            throw ValidationException::withMessages(['body' => ['The before field must be a string.']]);
        }

        if (array_key_exists('after', $payload) && ! is_string($payload['after'])) {
            throw ValidationException::withMessages(['body' => ['The after field must be a string.']]);
        }

        $phase = DeploymentPhase::tryFrom($payload['phase'] ?? DeploymentPhase::BeforeActivation->value);

        if (! $phase instanceof DeploymentPhase) {
            throw ValidationException::withMessages(['body' => ['The phase must be before_activation or after_activation.']]);
        }

        try {
            $this->step = new DeploymentStep(
                $payload['name'],
                $phase,
                $payload['command'],
                is_int($payload['timeout_seconds'] ?? null)
                    ? $payload['timeout_seconds']
                    : DeploymentStep::DefaultTimeoutSeconds,
            );
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['body' => ['A deployment step is invalid.']]);
        }

        $this->before = is_string($payload['before'] ?? null) ? $payload['before'] : null;
        $this->after = is_string($payload['after'] ?? null) ? $payload['after'] : null;

        return ['deploy_step' => true];
    }

    public function step(): DeploymentStep
    {
        if (! $this->step instanceof DeploymentStep) {
            throw new UnexpectedValueException('The deploy step was not validated.');
        }

        return $this->step;
    }

    public function before(): ?string
    {
        return $this->before;
    }

    public function after(): ?string
    {
        return $this->after;
    }
}
