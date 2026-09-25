<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Models\Node;

/**
 * Builds and pushes one Node's whole Caddyfile (ADR 0141). A caller commits its state change first
 * and never calls this inside a database transaction, so a concurrent build on the same Node reads
 * the change and a rollback never leaves a pushed site without a record.
 */
final readonly class NodeCaddyBuilder implements NodeCaddyBuilds
{
    public function __construct(
        private NodeCaddyfileRenderer $renderer,
        private NodeCaddyBuildLock $lock,
        private NodeCaddyTransport $transport,
        private NodeCaddyPushScript $script = new NodeCaddyPushScript,
    ) {}

    public function build(Node $node): NodeCaddyBuildResult
    {
        return $this->lock->run($node->id, $node->name, function () use ($node): NodeCaddyBuildResult {
            // Stored state is read only once the lock is held, so the last build reflects the last commit.
            $current = Node::query()->find($node->id);

            if (! $current instanceof Node) {
                throw new NodeCaddyBuildException($node->name, 'render', 'The Node no longer exists.');
            }

            $caddyfile = $this->renderer->render($current);

            if (! $caddyfile->buildable()) {
                throw new NodeCaddyBuildException($current->name, 'render', implode(' ', $caddyfile->problems));
            }

            $result = $this->transport->run($current, $this->script->command($caddyfile));

            if (! $result->succeeded()) {
                throw new NodeCaddyBuildException(
                    $current->name,
                    self::stage($result->stderr),
                    self::message($result->stderr),
                );
            }

            return str_contains($result->stdout, 'orbit-caddy-build-result=unchanged')
                ? NodeCaddyBuildResult::Unchanged
                : NodeCaddyBuildResult::Published;
        });
    }

    public function checkListenAddresses(Node $node): void
    {
        $caddyfile = $this->renderer->render($node);

        if (! $caddyfile->buildable()) {
            throw new NodeCaddyBuildException($node->name, 'render', implode(' ', $caddyfile->problems));
        }

        if ($caddyfile->listenAddresses === []) {
            return;
        }

        $result = $this->transport->run($node, $this->script->addressCheck($caddyfile));

        if (! $result->succeeded()) {
            throw new NodeCaddyBuildException($node->name, self::stage($result->stderr), self::message($result->stderr));
        }
    }

    private static function stage(string $stderr): string
    {
        return preg_match_all('/^orbit-caddy-build-stage=([a-z-]+)$/m', $stderr, $matches) > 0
            ? array_last($matches[1])
            : 'connect';
    }

    /** The last lines Caddy or the script wrote, without the stage marker. */
    private static function message(string $stderr): string
    {
        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $stderr)),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, 'orbit-caddy-build-stage='),
        ));

        $message = implode(' ', array_slice($lines, -5));

        return $message === '' ? 'The push script failed without a message.' : mb_substr($message, 0, 2000);
    }
}
