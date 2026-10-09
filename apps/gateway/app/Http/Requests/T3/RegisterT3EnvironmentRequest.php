<?php

declare(strict_types=1);

namespace App\Http\Requests\T3;

use App\Data\T3\RegisterT3EnvironmentData;
use App\Domain\T3\T3PairingUrl;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A T3 server's registration: its identity, the URL devices reach it at over WireGuard, and an
 * admin pairing link it minted for itself in T3's `<origin>/pair#token=<token>` form.
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
            'pairing_url' => ['required', 'string', 'max:4096', 'url:http,https', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || T3PairingUrl::token($value) === null) {
                    $fail('The pairing url must carry a pairing token.');
                }
            }],
        ];
    }

    public function payload(): RegisterT3EnvironmentData
    {
        $token = T3PairingUrl::token($this->string('pairing_url')->toString());
        assert(is_string($token));

        return new RegisterT3EnvironmentData(
            environmentId: $this->string('environment_id')->toString(),
            label: $this->string('label')->toString(),
            url: rtrim($this->string('url')->toString(), '/'),
            pairingToken: $token,
        );
    }
}
