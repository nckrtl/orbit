<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Domain\Tasks\TaskDefinitionJson;
use App\Support\ValidatedData;
use stdClass;

/** The status and body an API operation answered an MCP tool call with. */
final readonly class ApiResult
{
    public function __construct(public int $status, public string $body) {}

    public function failed(): bool
    {
        return $this->status >= 400;
    }

    /**
     * The JSON document, or each line of a newline-delimited JSON stream as one `events` entry.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if (trim($this->body) === '') {
            return null;
        }

        $document = $this->decode($this->body);

        if ($document !== null) {
            return $document;
        }

        $events = [];

        foreach (preg_split('/\R/', trim($this->body)) ?: [] as $line) {
            $event = $this->decode($line);

            if ($event === null) {
                return null;
            }

            $events[] = $event;
        }

        return ['events' => $events];
    }

    /** @return array<string, mixed>|null */
    private function decode(string $json): ?array
    {
        $decoded = TaskDefinitionJson::decode($json);

        if ($decoded instanceof stdClass) {
            return [];
        }

        if (! is_array($decoded)) {
            return null;
        }

        return ValidatedData::object($decoded);
    }
}
