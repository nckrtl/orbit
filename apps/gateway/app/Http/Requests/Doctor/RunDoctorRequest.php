<?php

declare(strict_types=1);

namespace App\Http\Requests\Doctor;

use App\Domain\Doctor\DoctorFamily;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use UnexpectedValueException;

final class RunDoctorRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'node_id' => ['sometimes', 'integer', 'min:1', $this->strictInteger(...)],
            'families' => ['sometimes', 'array', 'min:1'],
            'families.*' => ['string', 'distinct', Rule::enum(DoctorFamily::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     * @mago-expect analysis:mixed-assignment Decoded request input is an untyped transport boundary.
     */
    public function validationData(): array
    {
        try {
            $data = app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['node_id', 'families'],
            );

            if (array_key_exists('families', $data)) {
                $decoded = json_decode($this->getContent(), flags: JSON_THROW_ON_ERROR);

                if (! $decoded instanceof stdClass || ! is_array($decoded->families)) {
                    throw new UnexpectedValueException('The families field must be an array.');
                }
            }

            return $data;
        } catch (UnexpectedValueException|JsonException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @mago-expect analysis:mixed-assignment Validated request input is an untyped transport boundary. */
    public function nodeId(): ?int
    {
        $nodeId = $this->validated('node_id');

        return is_int($nodeId) ? $nodeId : null;
    }

    /** @return list<DoctorFamily> */
    public function families(): array
    {
        /** @var list<string> $families */
        $families = $this->validated('families', []);

        return array_map(
            DoctorFamily::from(...),
            array_values($families),
        );
    }

    private function strictInteger(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail("The {$attribute} field must be an integer.");
        }
    }
}
