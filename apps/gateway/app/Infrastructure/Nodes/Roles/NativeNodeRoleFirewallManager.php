<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Firewall\RouterLanIngressPublisher;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Firewall\UfwStatusParser;
use App\Infrastructure\Firewall\UfwStoredRuleParser;
use App\Infrastructure\Firewall\UfwStoredRuleProbe;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class NativeNodeRoleFirewallManager implements NodeRoleFirewallManager, RouterLanIngressPublisher
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private UfwStatusParser $statusParser = new UfwStatusParser,
        private UfwStoredRuleParser $storedParser = new UfwStoredRuleParser,
        private NodeFirewallRuleCatalog $catalog = new NodeFirewallRuleCatalog,
    ) {}

    public function convergeBase(Node $node, string $managedUser): void
    {
        $this->convergeRules(
            $node,
            [$this->publicSshRule($node)],
            publicConnection: true,
            enable: true,
            managedUser: $managedUser,
        );
    }

    public function converge(Node $node, RoleName $role, string $managedUser): void
    {
        $rules = $this->uniqueRules([$this->wireguardSshRule($node), ...$this->roleRules($node, $role)]);

        $this->convergeRules($node, $rules, publicConnection: false, enable: false, managedUser: $managedUser);
        $this->removeRules($node, [$this->publicSshRule($node)], $managedUser);
        $this->removeRules($node, $this->catalog->retiredForRole($node, $role), $managedUser);

        if ($role !== RoleName::Router) {
            return;
        }

        $this->reconcileRouterLanIngress(
            $node,
            $this->catalog->routerLanIngress($node),
            $managedUser,
            expand: true,
            prune: true,
            recovering: false,
        );
    }

    public function remove(Node $node, RoleName $role, string $managedUser): void
    {
        $rules = $this->roleRules($node, $role, owned: true);

        if ($rules !== []) {
            $this->removeRules($node, $rules, $managedUser);
        }

        if ($role === RoleName::Router) {
            $this->reconcileRouterLanIngress($node, [], $managedUser, expand: false, prune: true, recovering: false);
        }
    }

    public function expandRouterLanIngress(Node $router, array $desired, string $managedUser): void
    {
        if ($desired === []) {
            return;
        }

        $this->reconcileRouterLanIngress($router, $desired, $managedUser, expand: true, prune: false, recovering: false);
    }

    public function pruneRouterLanIngress(Node $router, array $desired, string $managedUser): void
    {
        $this->reconcileRouterLanIngress($router, $desired, $managedUser, expand: false, prune: true, recovering: false);
    }

    public function trustWireGuardMembers(Node $node, string $managedUser): void
    {
        $this->convergeRules(
            $node,
            [$this->wireguardSshRule($node)],
            publicConnection: false,
            enable: false,
            managedUser: $managedUser,
        );
    }

    public function restorePublicSsh(Node $node, string $managedUser): void
    {
        $this->convergeRules(
            $node,
            [$this->publicSshRule($node)],
            publicConnection: false,
            enable: false,
            managedUser: $managedUser,
        );
    }

    /** @param non-empty-list<UfwManagedRule> $rules */
    private function removeRules(Node $node, array $rules, string $managedUser): void
    {
        [$status, $inactive] = $this->status($node, publicConnection: false, managedUser: $managedUser);

        if ($inactive) {
            $this->fail($node, 'UFW is inactive during role-rule removal.', $status);
        }

        $numbers = [];

        foreach ($rules as $rule) {
            $ownership = $this->statusParser->ownership($status->stdout, $rule->shape);

            if ($ownership === UfwRuleOwnership::Drift) {
                $this->drift($node, $rule, $status);
            }

            if ($ownership === UfwRuleOwnership::Missing) {
                continue;
            }

            array_push($numbers, ...$this->ruleNumbers($status->stdout, $rule->shape->comment));
        }

        rsort($numbers, SORT_NUMERIC);

        foreach ($numbers as $number) {
            $result = $this->execute(
                $node,
                new RemoteCommand(['sudo', 'ufw', '--force', 'delete', (string) $number]),
                publicConnection: false,
                managedUser: $managedUser,
            );

            if (! $result->succeeded()) {
                $this->fail($node, "Could not delete owned UFW rule [{$number}].", $result);
            }
        }

        [$verification, $inactive] = $this->status($node, publicConnection: false, managedUser: $managedUser);

        if ($inactive) {
            $this->fail($node, 'UFW became inactive after role-rule removal.', $verification);
        }

        foreach ($rules as $rule) {
            if ($this->statusParser->ownership($verification->stdout, $rule->shape) === UfwRuleOwnership::Missing) {
                continue;
            }

            $this->drift($node, $rule, $verification);
        }
    }

    /**
     * @param  non-empty-list<UfwManagedRule>  $rules
     */
    private function convergeRules(
        Node $node,
        array $rules,
        bool $publicConnection,
        bool $enable,
        string $managedUser,
    ): void {
        [$status, $inactive] = $this->status($node, $publicConnection, $managedUser);

        if ($inactive) {
            if (! $enable) {
                $this->fail($node, 'UFW is inactive during role-rule convergence.', $status);
            }

            $stored = $this->storedRules($node, $managedUser);
            $this->guardStoredDrift($node, $stored, $rules);

            if ($this->storedParser->ownership($stored->stdout, $rules[0]->shape) === UfwRuleOwnership::Missing) {
                $this->apply($node, $rules[0], publicConnection: true, managedUser: $managedUser);
                $stored = $this->storedRules($node, $managedUser);
            }

            if ($this->storedParser->ownership($stored->stdout, $rules[0]->shape) !== UfwRuleOwnership::Exact) {
                $this->fail($node, 'Could not verify the exact stored public SSH recovery rule.', $stored);
            }

            $enabled = $this->execute(
                $node,
                new RemoteCommand(['sudo', 'ufw', '--force', 'enable']),
                publicConnection: true,
                managedUser: $managedUser,
            );

            if (! $enabled->succeeded()) {
                $this->fail($node, 'Could not enable UFW.', $enabled);
            }

            [$status, $inactive] = $this->status($node, publicConnection: true, managedUser: $managedUser);

            if ($inactive) {
                $this->fail($node, 'UFW remained inactive after it was enabled.', $status);
            }
        }

        $missing = [];

        foreach ($rules as $rule) {
            $ownership = $this->statusParser->ownership($status->stdout, $rule->shape);

            if ($ownership === UfwRuleOwnership::Drift) {
                $this->drift($node, $rule, $status);
            }

            if ($ownership === UfwRuleOwnership::Missing) {
                $missing[] = $rule;
            }
        }

        foreach ($missing as $rule) {
            $this->apply($node, $rule, $publicConnection, $managedUser);
        }

        [$verification, $inactive] = $this->status($node, $publicConnection, $managedUser);

        if ($inactive) {
            $this->fail($node, 'UFW was inactive after convergence.', $verification);
        }

        foreach ($rules as $rule) {
            if ($this->statusParser->ownership($verification->stdout, $rule->shape) === UfwRuleOwnership::Exact) {
                continue;
            }

            $this->drift($node, $rule, $verification);
        }
    }

    /** @return array{CommandResult, bool} */
    private function status(Node $node, bool $publicConnection, string $managedUser): array
    {
        $result = $this->execute(
            $node,
            new RemoteCommand(['sudo', 'ufw', 'status', 'numbered']),
            $publicConnection,
            $managedUser,
        );

        if (! $result->succeeded()) {
            $this->fail($node, 'Could not inspect UFW.', $result);
        }

        $inactive = preg_match('/^Status:\s+inactive$/mi', $result->stdout) === 1;

        if (! $inactive && preg_match('/^Status:\s+active$/mi', $result->stdout) !== 1) {
            $this->fail($node, 'UFW returned an unrecognized status.', $result);
        }

        return [$result, $inactive];
    }

    private function storedRules(Node $node, string $managedUser): CommandResult
    {
        $result = $this->execute(
            $node,
            new RemoteCommand(UfwStoredRuleProbe::arguments()),
            publicConnection: true,
            managedUser: $managedUser,
        );

        if (! $result->succeeded()) {
            $this->fail($node, 'Could not inspect protected stored UFW rules.', $result);
        }

        return $result;
    }

    /** @param non-empty-list<UfwManagedRule> $rules */
    private function guardStoredDrift(Node $node, CommandResult $stored, array $rules): void
    {
        foreach ($rules as $rule) {
            if ($this->storedParser->ownership($stored->stdout, $rule->shape) !== UfwRuleOwnership::Drift) {
                continue;
            }

            $this->drift($node, $rule, $stored);
        }
    }

    private function apply(Node $node, UfwManagedRule $rule, bool $publicConnection, string $managedUser): void
    {
        $result = $this->execute($node, new RemoteCommand($rule->arguments), $publicConnection, $managedUser);

        if (! $result->succeeded()) {
            $this->fail($node, "Could not apply [{$rule->shape->comment}].", $result);
        }
    }

    private function execute(
        Node $node,
        RemoteCommand $command,
        bool $publicConnection,
        string $managedUser,
    ): CommandResult {
        return $this->ssh->execute($this->connection($node, $publicConnection, $managedUser), $command);
    }

    private function connection(Node $node, bool $publicConnection, string $managedUser): SshConnection
    {
        $host = $publicConnection ? $node->public_ssh_host : $node->wireguard_ip;
        $port = $publicConnection ? $node->public_ssh_port : 22;

        if (! is_string($host) || $host === '') {
            throw new FirewallOperationException(
                step: 'host-firewall',
                errorCode: 'node.firewall_convergence_failed',
                message: "Node [{$node->name}] has no reachable firewall address.",
            );
        }

        return new SshConnection(
            host: $host,
            user: $managedUser,
            port: $port,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
        );
    }

    private function publicSshRule(Node $node): UfwManagedRule
    {
        return $this->catalog->forNode($node)[0];
    }

    private function wireguardSshRule(Node $node): UfwManagedRule
    {
        return $this->catalog->forNode($node)[1];
    }

    /**
     * The role's rules without the Node baseline and Router LAN rules. `$owned` selects every rule the role can
     * own, which removal closes, instead of the rules its current condition requires.
     *
     * @return list<UfwManagedRule>
     */
    private function roleRules(Node $node, RoleName $role, bool $owned = false): array
    {
        $baselineComments = array_map(
            static fn (UfwManagedRule $rule): string => $rule->shape->comment,
            $this->catalog->forNode($node),
        );

        $policy = new RouterLanIngressPolicy;

        return array_values(array_filter(
            $owned ? $this->catalog->ownedByRole($node, $role) : $this->catalog->forRole($node, $role),
            static fn (UfwManagedRule $rule): bool => ! in_array(
                $rule->shape->comment,
                $baselineComments,
                strict: true,
            ) && ! $policy->ownsComment($rule->shape->comment),
        ));
    }

    /**
     * @param  list<UfwManagedRule>  $desired
     */
    private function reconcileRouterLanIngress(
        Node $node,
        array $desired,
        string $managedUser,
        bool $expand,
        bool $prune,
        bool $recovering,
    ): void {
        if (! $expand && ! $prune) {
            return;
        }

        [$status, $inactive] = $this->status($node, publicConnection: false, managedUser: $managedUser);

        if ($inactive) {
            $this->fail($node, 'UFW is inactive during Router LAN ingress reconciliation.', $status);
        }

        $snapshot = $this->familyRules($status->stdout);
        $desiredByComment = [];

        foreach ($desired as $rule) {
            $desiredByComment[$rule->shape->comment] = $rule;
        }

        $toAdd = [];
        $toReplace = [];
        $toRemove = [];

        foreach ($desiredByComment as $comment => $rule) {
            $ownership = $this->statusParser->ownership($status->stdout, $rule->shape);

            if ($ownership === UfwRuleOwnership::Missing) {
                $toAdd[] = $rule;
            }

            if ($ownership === UfwRuleOwnership::Drift) {
                $toReplace[] = $rule;
            }
        }

        foreach ($snapshot as $observed) {
            if (array_key_exists($observed->shape->comment, $desiredByComment)) {
                continue;
            }

            $toRemove[] = $observed;
        }

        $mutating = ($expand && ($toAdd !== [] || $toReplace !== []))
            || ($prune && $toRemove !== []);

        if (! $mutating) {
            return;
        }

        try {
            if ($expand) {
                foreach ($toAdd as $rule) {
                    $this->apply($node, $rule, publicConnection: false, managedUser: $managedUser);
                }

                if ($toReplace !== []) {
                    $this->deleteOwnedComments(
                        $node,
                        array_map(static fn (UfwManagedRule $rule): string => $rule->shape->comment, $toReplace),
                        $managedUser,
                    );
                }

                foreach ($toReplace as $rule) {
                    $this->apply($node, $rule, publicConnection: false, managedUser: $managedUser);
                }
            }

            if ($prune && $toRemove !== []) {
                $this->deleteOwnedComments(
                    $node,
                    array_map(static fn (UfwManagedRule $rule): string => $rule->shape->comment, $toRemove),
                    $managedUser,
                );
            }

            $this->assertFamily($node, $desired, $expand, $prune, $managedUser);
        } catch (FirewallOperationException $exception) {
            if ($recovering) {
                throw $exception;
            }

            $this->restoreFamily($node, $snapshot, $managedUser, $exception);
        }
    }

    /** @param non-empty-list<string> $comments */
    private function deleteOwnedComments(Node $node, array $comments, string $managedUser): void
    {
        [$status, $inactive] = $this->status($node, publicConnection: false, managedUser: $managedUser);

        if ($inactive) {
            $this->fail($node, 'UFW is inactive during Router LAN ingress removal.', $status);
        }

        $numbers = [];

        foreach ($comments as $comment) {
            array_push($numbers, ...$this->ruleNumbers($status->stdout, $comment));
        }

        rsort($numbers, SORT_NUMERIC);

        foreach ($numbers as $number) {
            $result = $this->execute(
                $node,
                new RemoteCommand(['sudo', 'ufw', '--force', 'delete', (string) $number]),
                publicConnection: false,
                managedUser: $managedUser,
            );

            if (! $result->succeeded()) {
                $this->fail($node, "Could not delete owned UFW rule [{$number}].", $result);
            }
        }
    }

    /**
     * @param  list<UfwManagedRule>  $desired
     */
    private function assertFamily(
        Node $node,
        array $desired,
        bool $expand,
        bool $prune,
        string $managedUser,
    ): void {
        [$verification, $inactive] = $this->status($node, publicConnection: false, managedUser: $managedUser);

        if ($inactive) {
            $this->fail($node, 'UFW became inactive after Router LAN ingress reconciliation.', $verification);
        }

        if ($expand) {
            foreach ($desired as $rule) {
                if ($this->statusParser->ownership($verification->stdout, $rule->shape) === UfwRuleOwnership::Exact) {
                    continue;
                }

                $this->drift($node, $rule, $verification);
            }
        }

        if (! $prune) {
            return;
        }

        $desiredComments = array_map(
            static fn (UfwManagedRule $rule): string => $rule->shape->comment,
            $desired,
        );

        foreach ($this->familyRules($verification->stdout) as $observed) {
            if (in_array($observed->shape->comment, $desiredComments, strict: true)) {
                continue;
            }

            $this->drift($node, $observed, $verification);
        }
    }

    /**
     * @param  list<UfwManagedRule>  $snapshot
     */
    private function restoreFamily(
        Node $node,
        array $snapshot,
        string $managedUser,
        FirewallOperationException $exception,
    ): never {
        try {
            $this->reconcileRouterLanIngress(
                $node,
                $snapshot,
                $managedUser,
                expand: true,
                prune: true,
                recovering: true,
            );
        } catch (FirewallOperationException $recovery) {
            throw new FirewallOperationException(
                step: 'host-firewall',
                errorCode: 'router.lan_ingress_recovery_failed',
                message: "Could not restore the preceding Router LAN ingress policy on node [{$node->name}].",
                result: $recovery->result,
                previous: $recovery,
            );
        }

        throw $exception;
    }

    /** @return list<UfwManagedRule> */
    private function familyRules(string $output): array
    {
        $rules = [];

        foreach ($this->statusParser->familyShapes($output, RouterLanIngressPolicy::CommentPrefix) as $shape) {
            $sourceNodeId = $this->sourceNodeId($shape->comment);

            if ($sourceNodeId === null) {
                continue;
            }

            $rules[] = $this->catalog->routerLanIngressRule($shape->source, $shape->destination, $sourceNodeId);
        }

        return $rules;
    }

    private function sourceNodeId(string $comment): ?int
    {
        $prefix = RouterLanIngressPolicy::CommentPrefix.':';

        if (! str_starts_with($comment, $prefix)) {
            return null;
        }

        $suffix = substr($comment, strlen($prefix));

        if (preg_match('/\A[1-9][0-9]*\z/D', $suffix) !== 1) {
            return null;
        }

        return (int) $suffix;
    }

    /**
     * @param  non-empty-list<UfwManagedRule>  $rules
     * @return non-empty-list<UfwManagedRule>
     */
    private function uniqueRules(array $rules): array
    {
        $unique = [];
        foreach ($rules as $rule) {
            $unique[$rule->shape->comment] = $rule;
        }

        $values = array_values($unique);

        return $values;
    }

    /** @return list<int> */
    private function ruleNumbers(string $output, string $comment): array
    {
        $numbers = [];

        foreach (explode("\n", $output) as $line) {
            $matches = [];

            if (
                preg_match(
                    '/^\[\s*(\d+)\].*#\s*'.preg_quote(str: $comment, delimiter: '/').'\s*$/',
                    trim($line),
                    $matches,
                ) === 1
            ) {
                $numbers[] = (int) $matches[1];
            }
        }

        return $numbers;
    }

    private function drift(Node $node, UfwManagedRule $rule, CommandResult $result): never
    {
        $this->fail($node, "Managed UFW rule [{$rule->shape->comment}] did not match its exact shape.", $result);
    }

    private function fail(Node $node, string $reason, CommandResult $result): never
    {
        throw new FirewallOperationException(
            step: 'host-firewall',
            errorCode: 'node.firewall_convergence_failed',
            message: "{$reason} Could not preserve SSH access and converge UFW on node [{$node->name}].",
            result: $result,
        );
    }
}
