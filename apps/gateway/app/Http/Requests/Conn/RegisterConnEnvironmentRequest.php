<?php

declare(strict_types=1);

namespace App\Http\Requests\Conn;

use App\Data\Conn\RegisterConnEnvironmentData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A T3 server's registration: its identity, the URL devices reach it at over WireGuard, and an admin
 * bearer session issued on its host with `t3 auth session issue --label "Orbit Gateway"`.
 */
final class RegisterConnEnvironmentRequest extends FormRequest
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

    public function payload(): RegisterConnEnvironmentData
    {
        return new RegisterConnEnvironmentData(
            environmentId: $this->string('environment_id')->toString(),
            label: $this->string('label')->toString(),
            url: rtrim($this->string('url')->toString(), '/'),
            adminSession: $this->string('admin_session')->toString(),
        );
    }
}
