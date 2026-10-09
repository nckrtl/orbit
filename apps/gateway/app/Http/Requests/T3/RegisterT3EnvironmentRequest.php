<?php

declare(strict_types=1);

namespace App\Http\Requests\T3;

use App\Data\T3\RegisterT3EnvironmentData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A T3 server's registration: its identity, the URL devices reach it at over WireGuard, and an admin
 * bearer session issued on its host with `t3 auth session issue --label "Orbit Gateway"`.
 */
final class RegisterT3EnvironmentRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'environment_id' => ['required', 'string', 'max:255', 'regex:/\A\S+\z/'],
            'label' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'admin_session' => ['required', 'string', 'max:4096', 'regex:/\A\S+\z/'],
        ];
    }

    public function payload(): RegisterT3EnvironmentData
    {
        return new RegisterT3EnvironmentData(
            environmentId: $this->string('environment_id')->toString(),
            label: $this->string('label')->toString(),
            url: rtrim($this->string('url')->toString(), '/'),
            adminSession: $this->string('admin_session')->toString(),
        );
    }
}
