<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Firewall\LiveFirewallBackendStatus;
use App\Infrastructure\Firewall\DesiredFirewallRule;
use App\Infrastructure\Firewall\FirewallLiveDriftClassifier;
use App\Infrastructure\Firewall\NativeLiveUfwReader;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Infrastructure\Firewall\UfwStatusParser;
use App\Models\FirewallRule;
use App\Models\Node;

final readonly class ListLiveFirewallRulesAction
{
    public function __construct(
        private NativeLiveUfwReader $live,
        private NodeFirewallDesiredRules $desired,
        private UfwStatusParser $parser = new UfwStatusParser,
        private FirewallLiveDriftClassifier $classifier = new FirewallLiveDriftClassifier,
    ) {}

    /**
     * Live IPv4 UFW rules on the Node, classified against the desired managed set and operator records.
     *
     * @return array{backend_status: string, live: list<array{name: string, comment: string, action: string, source: string, destination: string, port: string, protocol: string, interface: ?string, family: ?string, match: string}>, missing: list<array{name: string, comment: string, action: string, source: string, destination: string, port: string, protocol: string, interface: ?string, family: ?string, match: string}>}
     */
    public function execute(Node $node): array
    {
        $status = $this->live->read($node);

        if ($status['backend'] !== LiveFirewallBackendStatus::Active) {
            return [
                'backend_status' => $status['backend']->value,
                'live' => [],
                'missing' => [],
            ];
        }

        $desired = $this->desired->forNode($node);
        $operator = $node->firewallRules()->with('node')->orderBy('name')->get();
        $expected = [
            ...array_map(static fn (DesiredFirewallRule $rule): UfwRuleShape => $rule->shape, $desired),
            ...$operator->map(static fn (FirewallRule $rule): UfwRuleShape => self::operatorShape($rule))->all(),
        ];
        $classified = $this->classifier->classify($this->parser->liveShapes($status['stdout']), $expected);
        $desiredByComment = [];

        foreach ($desired as $rule) {
            $desiredByComment[$rule->shape->comment] = $rule;
        }

        $operatorByComment = [];

        foreach ($operator as $rule) {
            $operatorByComment[FirewallInspectionTarget::fromRule($rule)->shape->comment] = $rule;
        }

        return [
            'backend_status' => LiveFirewallBackendStatus::Active->value,
            'live' => array_map(
                fn (array $row): array => $this->row(
                    $row['shape'],
                    $row['match'],
                    $desiredByComment,
                    $operatorByComment,
                ),
                $classified['live'],
            ),
            'missing' => array_map(
                fn (UfwRuleShape $shape): array => $this->row(
                    $shape,
                    'missing',
                    $desiredByComment,
                    $operatorByComment,
                ),
                $classified['missing'],
            ),
        ];
    }

    /**
     * @param  array<string, DesiredFirewallRule>  $desiredByComment
     * @param  array<string, FirewallRule>  $operatorByComment
     * @return array{name: string, comment: string, action: string, source: string, destination: string, port: string, protocol: string, interface: ?string, family: ?string, match: string}
     */
    private function row(
        UfwRuleShape $shape,
        string $match,
        array $desiredByComment,
        array $operatorByComment,
    ): array {
        $name = array_key_exists($shape->comment, $operatorByComment)
            ? $operatorByComment[$shape->comment]->name
            : (array_key_exists($shape->comment, $desiredByComment)
                ? $desiredByComment[$shape->comment]->name
                : $this->displayName($shape->comment));

        return [
            'name' => $name,
            'comment' => $shape->comment,
            'action' => $shape->action,
            'source' => $shape->source,
            'destination' => $shape->destination,
            'port' => $shape->port,
            'protocol' => $shape->protocol,
            'interface' => $shape->inInterface,
            'family' => $shape->family,
            'match' => $match,
        ];
    }

    private function displayName(string $comment): string
    {
        if (preg_match('/\Aorbit:node:\d+:firewall:(.+)\z/', $comment, $matches) === 1) {
            return $matches[1];
        }

        return $comment !== '' ? $comment : 'unmanaged';
    }

    private static function operatorShape(FirewallRule $rule): UfwRuleShape
    {
        $shape = FirewallInspectionTarget::fromRule($rule)->shape;

        return new UfwRuleShape(
            comment: $shape->comment,
            action: $shape->action,
            direction: $shape->direction,
            source: $shape->source,
            destination: $shape->destination,
            port: $shape->port,
            protocol: $shape->protocol,
            inInterface: $shape->inInterface,
            outInterface: $shape->outInterface,
            family: $shape->family,
        );
    }
}
