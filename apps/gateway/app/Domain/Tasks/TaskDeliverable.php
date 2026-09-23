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
        public string $project = '.',
        public string $file = '',
        public string $name = '',
        public string $command = '',
        public string $directory = '.',
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
            project: $text('project', '.'),
            file: $text('file'),
            name: $text('name'),
            command: $text('command'),
            directory: $text('directory', '.'),
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

    /** @return array<string, string> the id, type, description, and the fields of the type */
    public function toArray(): array
    {
        $fields = ['id' => $this->id, 'type' => $this->type->value, 'description' => $this->description];
        foreach ($this->type->fields() as $field) {
            $fields[$field] = $this->{$field};
        }

        return $fields;
    }

    /** The path of a test file from the workspace root. */
    public function testPath(): string
    {
        return self::join($this->project, $this->file);
    }

    /** One line that names the deliverable and what it asks for, for agent prompts. */
    public function line(): string
    {
        $detail = match ($this->type) {
            TaskDeliverableType::File => "{$this->path}, {$this->change}",
            TaskDeliverableType::Test => "Pest test \"{$this->name}\" in {$this->testPath()}",
            TaskDeliverableType::Command => "`{$this->command}` in {$this->directory}",
            TaskDeliverableType::Review => 'confirmed by the reviewer',
        };

        return "- {$this->id} ({$this->type->value}: {$detail}): {$this->description}";
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
