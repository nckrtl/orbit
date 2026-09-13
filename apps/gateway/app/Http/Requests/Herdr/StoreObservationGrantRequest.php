<?php

declare(strict_types=1);

namespace App\Http\Requests\Herdr;

use App\Data\Herdr\IssueObservationGrantData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreObservationGrantRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'pane' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/D'],
            'terminal' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/D'],
            'cols' => ['required', 'integer', 'min:1', 'max:500'],
            'rows' => ['required', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function payload(): IssueObservationGrantData
    {
        return new IssueObservationGrantData(
            pane: (string) $this->validated('pane'),
            terminal: (string) $this->validated('terminal'),
            cols: (int) $this->validated('cols'),
            rows: (int) $this->validated('rows'),
        );
    }
}
