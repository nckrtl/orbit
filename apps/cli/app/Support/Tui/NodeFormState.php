<?php

declare(strict_types=1);

namespace App\Support\Tui;

use App\Support\Console\Renderers\TableTheme;
use App\Support\Tui\Prompts\PanelMultiSelectPrompt;
use App\Support\Tui\Prompts\PanelMultiSelectPromptRenderer;
use App\Support\Tui\Prompts\PanelTextPrompt;
use App\Support\Tui\Prompts\PanelTextPromptRenderer;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use RuntimeException;

/**
 * The node create form: the panel-drawn prompts `node:add` would ask, the answers so far, and
 * which field (or the submit button, one past the last field) currently has the focus.
 *
 * The real `node:add` command takes its input as arguments and options rather than prompts, so
 * this form's fields and client-side validation are a UX addition, not a copy of an existing
 * prompt flow; they mirror the same argument names and the same server-side constraints
 * `AddNodeCommand` enforces (SSH port range, for example) so a value accepted here is accepted
 * by the command.
 */
final class NodeFormState
{
    private const array FIELDS = ['name', 'host', 'port', 'user', 'roles', 'tld'];

    /** @var list<array{string, PanelTextPrompt|PanelMultiSelectPrompt}> */
    public readonly array $prompts;

    public int $active = 0;

    public ?string $error = null;

    public function __construct()
    {
        // The theme finds a renderer by concrete class, so the panel prompt subclasses reuse
        // the CLI's own renderers, drawn inside the panel instead of on the raw terminal.
        TableTheme::extend([
            PanelTextPrompt::class => PanelTextPromptRenderer::class,
            PanelMultiSelectPrompt::class => PanelMultiSelectPromptRenderer::class,
        ]);
        Prompt::addTheme('orbit-cli', TableTheme::renderers());
        Prompt::theme('orbit-cli');

        $this->prompts = array_map(fn (string $key): array => [$key, $this->prompt($key)], self::FIELDS);
    }

    private function prompt(string $key): PanelTextPrompt|PanelMultiSelectPrompt
    {
        return match ($key) {
            'name' => PanelTextPrompt::make(
                label: 'Node name',
                placeholder: 'beast',
                required: true,
                validate: static fn (string $v): ?string => preg_match('/^[a-z0-9-]+$/', $v) === 1 ? null : 'Use lowercase letters, digits, and dashes.',
            ),
            'host' => PanelTextPrompt::make(
                label: 'SSH host',
                placeholder: '10.0.0.12 or beast.example.test',
                required: false,
                validate: static fn (string $v): ?string => $v === '' || filter_var($v, FILTER_VALIDATE_IP) !== false || preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $v) === 1 ? null : 'Enter an IP address or a host name such as beast.example.test.',
            ),
            'port' => PanelTextPrompt::make(
                label: 'SSH port',
                default: '22',
                required: true,
                validate: static fn (string $v): ?string => ctype_digit($v) && (int) $v > 0 && (int) $v < 65_536 ? null : 'A port is a number from 1 to 65535.',
            ),
            'user' => PanelTextPrompt::make(label: 'SSH user', default: 'root', required: true),
            'roles' => PanelMultiSelectPrompt::make(
                label: 'Roles',
                options: [
                    'app-dev' => 'app-dev · runs App instances',
                    'app-prod' => 'app-prod · runs production App instances',
                    'gateway' => 'gateway · runs the Gateway and the VPN hub',
                ],
                default: ['app-dev'],
                required: 'Pick at least one role.',
                hint: 'Space toggles a role.',
            ),
            'tld' => PanelTextPrompt::make(label: 'TLD for its domains', required: false),
            default => throw new RuntimeException("No prompt for {$key}."),
        };
    }

    /** Keys go to the active field. Enter confirms it (the prompt validates itself) and moves on. */
    public function press(string $key): void
    {
        if ($this->active >= count($this->prompts)) {
            return;
        }

        [, $prompt] = $this->prompts[$this->active];
        $prompt->press($key);

        if ($key === Key::ENTER && $prompt->done()) {
            $this->focusField($this->active + 1);
        }
    }

    /** A field takes the focus in its editable state; past the last field sits the submit button. */
    public function focusField(int $index): void
    {
        $index = max(0, min(count($this->prompts), $index));
        $this->active = $index;

        if (isset($this->prompts[$index]) && $this->prompts[$index][1]->state === 'submit') {
            $this->prompts[$index][1]->state = 'active';
        }
    }

    public function onButton(): bool
    {
        return $this->active === count($this->prompts);
    }

    /** Confirms every field; the first invalid one takes the focus. Returns true when all are valid. */
    public function validate(): bool
    {
        $firstInvalid = null;

        foreach ($this->prompts as $index => [, $field]) {
            if (! $field->done()) {
                $field->press(Key::ENTER);
            }

            if (! $field->done()) {
                $firstInvalid ??= $index;
            }
        }

        if ($firstInvalid !== null) {
            $this->focusField($firstInvalid);

            return false;
        }

        return true;
    }

    /** @return array{name: string, host: string, port: string, user: string, roles: list<string>, tld: string} */
    public function values(): array
    {
        $values = [];

        foreach ($this->prompts as [$name, $prompt]) {
            $values[$name] = $prompt->value();
        }

        return $values;
    }
}
