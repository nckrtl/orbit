<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * One typed item a subtask must deliver. The API validates the shape before a deliverable is stored.
 */
final readonly class TaskDeliverable
{
    public function __construct(
        public string $id,
        public TaskDeliverableType $type,
        public string $description,
        public string $path = '',
        public string $change = 'any',
        public string $command = '',
        public string $directory = '.',
        public bool $fails_on_base = false,
        /** @var list<string> */
        public array $paths = [],
        private bool $has_fails_on_base = false,
        private bool $has_paths = false,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $text = static fn (string $key, string $default = ''): string => is_string($data[$key] ?? null) ? $data[$key] : $default;

        return new self(
            id: $text('id'),
            type: TaskDeliverableType::tryFrom($text('type')) ?? TaskDeliverableType::Review,
            description: $text('description'),
            path: $text('path'),
            change: $text('change', 'any'),
            command: $text('command'),
            directory: $text('directory', '.'),
            fails_on_base: ($data['fails_on_base'] ?? null) === true,
            paths: is_array($data['paths'] ?? null) ? array_values(array_filter($data['paths'], is_string(...))) : [],
            has_fails_on_base: array_key_exists('fails_on_base', $data),
            has_paths: array_key_exists('paths', $data),
        );
    }

    /**
     * @param  mixed  $stored  the task's stored deliverables column
     * @return list<self>
     */
    public static function listFrom(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_map(self::fromArray(...), array_filter($stored, is_array(...))));
    }

    /** @return array<string, string|bool|list<string>> the id, type, description, and the fields of the type */
    public function toArray(): array
    {
        $fields = ['id' => $this->id, 'type' => $this->type->value, 'description' => $this->description];
        foreach ($this->type->fields() as $field) {
            $fields[$field] = $this->{$field};
        }
        if ($this->type === TaskDeliverableType::Command) {
            if ($this->has_fails_on_base || $this->fails_on_base) {
                $fields['fails_on_base'] = $this->fails_on_base;
            }
            if ($this->has_paths || $this->paths !== []) {
                $fields['paths'] = $this->paths;
            }
        }

        return $fields;
    }

    /** One line that names the deliverable and what it asks for, for agent prompts. */
    public function line(): string
    {
        $detail = match ($this->type) {
            TaskDeliverableType::File => "{$this->path}, {$this->change}",
            TaskDeliverableType::Command => $this->commandLine(),
            TaskDeliverableType::Review => 'confirmed by the reviewer',
        };

        return "- {$this->id} ({$this->type->value}: {$detail}): {$this->description}";
    }

    /** Render command and base-run behavior in implementer and reviewer prompts. */
    private function commandLine(): string
    {
        $detail = "`{$this->command}` in ".(self::relative($this->directory) ?: '.');
        if ($this->fails_on_base) {
            $detail .= '; paths '.implode(', ', $this->paths).' are overlaid on the start commit, where it must fail before passing on the working tree';
        }

        return $detail;
    }

    /** Joins a directory and a path from it into one path from the workspace root. */
    public static function join(string $directory, string $path): string
    {
        $directory = self::relative($directory);
        $path = self::relative($path);

        return $directory === '' ? $path : ($path === '' ? $directory : $directory.'/'.$path);
    }

    /** A relative path without leading `./` or slashes; `.` becomes empty. */
    public static function relative(string $path): string
    {
        $path = (string) preg_replace('#^(?:\./|/)+#', '', trim($path));

        return $path === '.' ? '' : rtrim($path, '/');
    }
}
