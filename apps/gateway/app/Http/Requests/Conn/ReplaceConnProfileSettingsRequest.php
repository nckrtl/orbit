<?php

declare(strict_types=1);

namespace App\Http\Requests\Conn;

use App\Data\Conn\ReplaceConnProfileSettingsData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The whole settings document of a profile. Its keys follow Conn's workspace store, so they stay
 * camelCase. A workspace covers its `projectRefs` and every project whose logical key is in
 * `projectKeys`, as Conn reads them; both are optional. `version` is the version the client last
 * read; a stale one is refused.
 */
final class ReplaceConnProfileSettingsRequest extends FormRequest
{
    /** The longest workspace picture Conn keeps (`WORKSPACE_IMAGE_MAX_CHARS`). */
    private const int IMAGE_MAX_CHARS = 200_000;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'settings' => ['required', 'array:workspaces'],
            'settings.workspaces' => ['present', 'list', 'max:200'],
            'settings.workspaces.*' => ['array:id,name,color,icon,image,projectRefs,projectKeys'],
            'settings.workspaces.*.id' => ['required', 'string', 'max:100', 'distinct'],
            'settings.workspaces.*.name' => ['required', 'string', 'max:100'],
            'settings.workspaces.*.color' => ['required', 'string', 'max:32'],
            'settings.workspaces.*.icon' => ['present', 'nullable', 'string', 'max:64'],
            'settings.workspaces.*.image' => ['sometimes', 'nullable', 'string', 'max:'.self::IMAGE_MAX_CHARS, 'regex:/\Adata:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/]+=*\z/'],
            'settings.workspaces.*.projectRefs' => ['sometimes', 'list', 'max:500'],
            'settings.workspaces.*.projectRefs.*' => ['string', 'max:512', 'regex:/\A[^:\s]+:\S+\z/'],
            'settings.workspaces.*.projectKeys' => ['sometimes', 'list', 'max:500'],
            'settings.workspaces.*.projectKeys.*' => ['string', 'max:512'],
        ];
    }

    public function payload(): ReplaceConnProfileSettingsData
    {
        $workspaces = $this->validated('settings.workspaces');

        return new ReplaceConnProfileSettingsData(
            version: $this->integer('version'),
            settings: ['workspaces' => is_array($workspaces) ? array_values($workspaces) : []],
        );
    }
}
