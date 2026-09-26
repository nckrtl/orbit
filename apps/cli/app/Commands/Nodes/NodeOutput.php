<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Support\Console\ConsoleMode;
use App\Support\Console\HumanRenderer;
use App\Support\Console\ProgressState;
use App\Support\Console\TerminalText;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Nodes\NodeAccessNodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;
use Orbit\Sdk\Responses\Nodes\RemovedNodeResponse;

final class NodeOutput
{
    /** @param list<NodeAccessNodeResponse> $nodes */
    public static function accessList(array $nodes): string
    {
        if ($nodes === []) {
            return '—';
        }

        return implode(', ', array_map(
            static fn (NodeAccessNodeResponse $node): string => "{$node->name} (#{$node->id})",
            $nodes,
        ));
    }

    public static function sshEndpoint(NodeResponse $node): string
    {
        if ($node->user === '' || $node->publicSshHost === '' || $node->publicSshPort < 1) {
            return '—';
        }

        return "{$node->user}@{$node->publicSshHost}:{$node->publicSshPort}";
    }

    public static function tld(?string $tld): ?string
    {
        return $tld === null || $tld === '' ? null : '.'.ltrim($tld, '.');
    }

    public static function mutationState(object $response, bool $removing = false): ProgressState
    {
        $valid = match (true) {
            $response instanceof NodeResponse => ! $removing && $response->status === 'active',
            $response instanceof RemovedNodeResponse => $removing && $response->removed,
            $response instanceof NodeRoleMutationResponse => $response->removed === $removing && ($removing || $response->assignment?->status === 'active'),
            default => false,
        };

        if (! $valid) {
            throw new GatewayApiException('Gateway response does not confirm the Node operation.',
                'gateway.invalid_response', requestId: $response instanceof NodeResponse
                    || $response instanceof RemovedNodeResponse || $response instanceof NodeRoleMutationResponse
                        ? $response->requestId : null);
        }

        $warning = match (true) {
            $response instanceof RemovedNodeResponse => $response->degradation !== null,
            $response instanceof NodeRoleMutationResponse => $response->degradation !== null || $response->followUp !== null,
            default => false,
        };

        return $warning ? ProgressState::Warning : ProgressState::Success;
    }

    /**
     * Warns about a convergence step that failed without failing the role, so the operator does not
     * learn about it only from Doctor or the Gateway log.
     */
    public static function followUpWarning(ConsoleMode $mode, ?string $followUp): string
    {
        if ($followUp === null || $mode->machine) {
            return '';
        }

        return TerminalText::style(
            implode("\n", TerminalText::wrapWords(TerminalText::safe("Warning: {$followUp}"), $mode->columns)),
            'orange',
            $mode->decorated,
        )."\n";
    }

    /**
     * @param  list<string>  $rolesShed
     * @param  list<string>  $retainedOnNode
     */
    public static function degradationAdvisory(
        HumanRenderer $renderer,
        ConsoleMode $mode,
        string $nodeName,
        ?string $degradation,
        array $rolesShed,
        array $retainedOnNode,
        ?string $followUp,
    ): string {
        if ($degradation === null || $mode->machine) {
            return '';
        }

        $warning = "Warning: Node [{$nodeName}] was ".self::degradationDescription($degradation)
            .'. Orbit removed only the state it owns.';
        $output = TerminalText::style(implode("\n", TerminalText::wrap(TerminalText::safe($warning), $mode->columns)), 'orange', $mode->decorated)."\n";
        $groups = [];

        foreach (['Roles shed' => $rolesShed, 'Left on the node' => $retainedOnNode] as $title => $items) {
            if ($items !== []) {
                $groups[] = ['title' => $title.':', 'items' => array_map(
                    static fn (string $item): array => ['label' => $item, 'fields' => []], $items)];
            }
        }

        $output .= $renderer->properties($groups);

        if ($followUp !== null) {
            $output .= implode("\n", TerminalText::wrap(TerminalText::safe($followUp), $mode->columns))."\n";
        }

        return $output;
    }

    private static function degradationDescription(string $degradation): string
    {
        return match ($degradation) {
            'firewall_inactive' => 'reachable, but its firewall was not active',
            default => 'unreachable',
        };
    }
}
