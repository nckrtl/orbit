<?php

declare(strict_types=1);

namespace App\Http\Requests\ProjectDocuments;

use App\Data\ProjectDocuments\UpdateDocumentStorageData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class UpdateDocumentStorageRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'endpoint' => ['sometimes', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                $parts = is_string($value) ? parse_url($value) : false;
                if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
                    || ! isset($parts['host']) || filter_var($value, FILTER_VALIDATE_URL) === false
                    || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []
                    || (isset($parts['port']) && $parts['port'] < 1)) {
                    $fail('The endpoint must be an HTTPS origin without userinfo, path, query, or fragment.');
                }
            }],
            'region' => ['sometimes', 'required', 'string', 'regex:/\A[\x21-\x7e]{1,63}\z/D'],
            'bucket' => ['sometimes', 'required', 'string', 'regex:/\A[a-z0-9](?:[a-z0-9.-]{1,61})[a-z0-9]\z/D', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (filter_var($value, FILTER_VALIDATE_IP) !== false
                    || preg_match('/\.\.|\.-|-\.|\Axn--|\Asthn-|\Aamzn-s3-demo-|-(?:s3alias|ol-s3)\z|\.(?:mrap|s3|s3express|s3tables)\z/', $value) === 1)) {
                    $fail('The bucket must be a valid S3 bucket name.');
                }
            }],
            'access_key_id' => $this->credentialRules('secret_access_key'),
            'secret_access_key' => $this->credentialRules('access_key_id'),
        ];
    }

    /** @return list<mixed> */
    private function credentialRules(string $other): array
    {
        return ['filled', 'required_with:'.$other, 'string', function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '' || strlen($value) > 1024) {
                $fail('Credential fields must contain between 1 and 1024 bytes.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['endpoint', 'region', 'bucket', 'access_key_id', 'secret_access_key'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function toData(): UpdateDocumentStorageData
    {
        return new UpdateDocumentStorageData(
            $this->stringValue('endpoint'),
            $this->stringValue('region'),
            $this->stringValue('bucket'),
            $this->stringValue('access_key_id'),
            $this->stringValue('secret_access_key'),
        );
    }

    private function stringValue(string $field): ?string
    {
        $value = $this->validated($field);

        return is_string($value) ? $value : null;
    }
}
