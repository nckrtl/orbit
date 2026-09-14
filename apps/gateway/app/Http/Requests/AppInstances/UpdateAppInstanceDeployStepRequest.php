<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Domain\AppInstances\Deployment\DeploymentPhase;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class UpdateAppInstanceDeployStepRequest extends FormRequest
{
    /** @var array<string, mixed> */
    private array $payload = [];

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
                ['command', 'phase', 'timeout_seconds', 'before', 'after'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }

        if ($payload === []) {
            throw ValidationException::withMessages(['body' => ['Provide at least one deploy step update field.']]);
        }

        if (array_key_exists('command', $payload) && ! is_string($payload['command'])) {
            throw ValidationException::withMessages(['body' => ['The command field must be a string.']]);
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

        if (array_key_exists('phase', $payload) && DeploymentPhase::tryFrom($payload['phase']) === null) {
            throw ValidationException::withMessages(['body' => ['The phase must be before_activation or after_activation.']]);
        }

        $this->payload = $payload;

        return ['deploy_step' => true];
    }

    public function hasCommand(): bool
    {
        return array_key_exists('command', $this->payload);
    }

    public function command(): ?string
    {
        return is_string($this->payload['command'] ?? null) ? $this->payload['command'] : null;
    }

    public function hasPhase(): bool
    {
        return array_key_exists('phase', $this->payload);
    }

    public function phase(): ?DeploymentPhase
    {
        return is_string($this->payload['phase'] ?? null) ? DeploymentPhase::from($this->payload['phase']) : null;
    }

    public function hasTimeout(): bool
    {
        return array_key_exists('timeout_seconds', $this->payload);
    }

    public function timeoutSeconds(): ?int
    {
        return is_int($this->payload['timeout_seconds'] ?? null) ? $this->payload['timeout_seconds'] : null;
    }

    public function beforeStep(): ?string
    {
        return is_string($this->payload['before'] ?? null) ? $this->payload['before'] : null;
    }

    public function afterStep(): ?string
    {
        return is_string($this->payload['after'] ?? null) ? $this->payload['after'] : null;
    }
}
