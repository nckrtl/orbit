<?php

declare(strict_types=1);

namespace App\Http\Requests\Annotations;

use App\Data\Annotations\AnnotationInput;
use Illuminate\Foundation\Http\FormRequest;

final class StoreAnnotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'threadId' => ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'comment' => ['required', 'string', 'max:20000'],
            'x' => ['required', 'numeric', 'between:0,100'],
            'y' => ['required', 'numeric'],
            'timestamp' => ['required', 'numeric', 'min:0'],
            'url' => ['required', 'url:http,https', 'max:4096'],
            'pathname' => ['required', 'string', 'max:4096'],
            'element' => ['required', 'string', 'max:1000'],
            'elementPath' => ['required', 'string', 'max:10000'],
            'isFixed' => ['sometimes', 'boolean'],
            'boundingBox' => ['sometimes', 'array:x,y,width,height'],
            'boundingBox.*' => ['numeric'],
            'screenshot' => ['nullable', 'string', 'max:3000000', 'regex:/^data:image\/(png|jpeg|webp);base64,/'],
            'component' => ['nullable', 'string', 'max:4096'],
            'controller' => ['nullable', 'string', 'max:4096'],
            'route' => ['nullable', 'string', 'max:4096'],
            'screenSize' => ['nullable', 'string', 'max:100'],
            'scrollPosition' => ['nullable', 'string', 'max:100'],
            'breakpoint' => ['nullable', 'string', 'max:100'],
            'react' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function payload(): AnnotationInput
    {
        /** @var array<string, mixed> $values */
        $values = $this->validated();

        return new AnnotationInput($values);
    }
}
