<?php

declare(strict_types=1);

namespace App\Http\Requests\ProjectDocuments;

use App\Data\ProjectDocuments\DocumentBody;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

abstract class DocumentRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    abstract public function rules(): array;

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $allowed = array_keys($this->rules());
        if (in_array($this->method(), ['GET', 'HEAD'], true)) {
            $data = $this->query->all();
            if (array_key_exists('parent_id', $data) && in_array($data['parent_id'], ['', 'null'], true)) {
                $data['parent_id'] = null;
            }
            if (is_string($data['q'] ?? null)) {
                $data['q'] = trim($data['q']);
            }
            if (array_diff(array_keys($data), $allowed) !== []) {
                throw ValidationException::withMessages(['query' => 'Unknown query fields.']);
            }

            return $data;
        }
        if ($this->query->all() !== []) {
            throw ValidationException::withMessages(['query' => 'Mutation query fields are not supported.']);
        }
        $length = $this->server('CONTENT_LENGTH');
        if ((is_numeric($length) && (float) $length > 15728640) || strlen($this->getContent()) > 15728640) {
            throw new ResourceOperationException('project_documents.content_too_large', 'Document request is too large.', 413);
        }
        try {
            $data = app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), $allowed);
            foreach ($this->rules() as $field => $rules) {
                if (array_key_exists($field, $data) && in_array('integer', $rules, true)
                    && ! is_int($data[$field]) && ! ($data[$field] === null && in_array('nullable', $rules, true))) {
                    throw ValidationException::withMessages([$field => 'Use a JSON integer.']);
                }
            }

            return $data;
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        }
    }

    public function integerValue(string $field, ?int $default = null): ?int
    {
        $value = $this->validated($field);

        return $value === null ? $default : (int) ValidatedData::string(is_int($value) ? (string) $value : $value);
    }

    public function textValue(string $field, ?string $default = null): ?string
    {
        return ValidatedData::nullableString($this->validated($field, $default));
    }

    public function revision(): int
    {
        return $this->integerValue('expected_revision') ?? 0;
    }

    public function bodyData(?string $defaultType = null): DocumentBody
    {
        $data = $this->validated();
        $text = array_key_exists('content_text', $data);
        $base64 = array_key_exists('content_base64', $data);
        if ($text === $base64) {
            throw ValidationException::withMessages(['content' => 'Supply exactly one content field.']);
        }
        $type = $this->textValue('media_type', $defaultType);

        return $text ? DocumentBody::text(ValidatedData::string($data['content_text']), $type ?? 'text/plain')
            : DocumentBody::base64(ValidatedData::string($data['content_base64']), $type ?? 'application/octet-stream');
    }
}
