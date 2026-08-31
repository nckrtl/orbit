<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Domain\Nodes\Storage\NodeSettingsParser;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Foundation\Http\FormRequest;
use JsonException;

final class UpdateNodeSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }

    public function payload(): NodeSettingsPatch
    {
        try {
            $decoded = json_decode($this->getContent(), associative: false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ResourceOperationException(
                errorCode: 'node.settings_invalid',
                message: 'The settings object is invalid.',
            );
        }

        return new NodeSettingsParser()->parsePatch($decoded);
    }
}
