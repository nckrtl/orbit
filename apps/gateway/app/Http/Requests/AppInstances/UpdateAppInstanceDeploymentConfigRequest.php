<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Domain\AppInstances\Deployment\DeploymentConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class UpdateAppInstanceDeploymentConfigRequest extends FormRequest
{
    private ?DeploymentConfig $deploymentConfig = null;

    /** @return array{} */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $this->deploymentConfig = app(DeploymentConfigInputParser::class)->parse($this->getContent());
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }

        return ['deployment_config' => true];
    }

    public function deploymentConfig(): DeploymentConfig
    {
        if (! $this->deploymentConfig instanceof DeploymentConfig) {
            throw new UnexpectedValueException('The deployment configuration was not validated.');
        }

        return $this->deploymentConfig;
    }
}
