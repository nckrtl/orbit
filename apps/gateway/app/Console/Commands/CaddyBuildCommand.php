<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Caddy\Build\CaddyfileSiteDiff;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyLiveReader;
use App\Models\Node;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders a Node's Caddy build without pushing it (ADR 0141). The build is not live yet, so the
 * command only runs with `--dry-run`. `--diff` compares the render with the live file site by site.
 */
final class CaddyBuildCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:caddy-build
        {node : Node name}
        {--dry-run : Render the Caddyfile without pushing it}
        {--diff : Compare the render with the live Caddyfile site by site}';

    #[\Override]
    protected $description = 'Render a Node Caddy build without pushing it.';

    public function handle(NodeCaddyfileRenderer $renderer, NodeCaddyLiveReader $live): int
    {
        if (! $this->option('dry-run')) {
            $this->error('The Node Caddy build is not live yet. Pass --dry-run to render it without pushing.');

            return self::FAILURE;
        }

        $name = (string) $this->argument('node');
        $node = Node::query()->where('name', $name)->first();

        if (! $node instanceof Node) {
            $this->error("Node [{$name}] does not exist.");

            return self::FAILURE;
        }

        $caddyfile = $renderer->render($node);

        if (! $this->option('diff')) {
            $this->output->write($caddyfile->content, false, OutputInterface::OUTPUT_RAW);
        } else {
            try {
                $this->diff($live->read($node), $caddyfile->content);
            } catch (NodeCaddyBuildException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->line("Build version {$caddyfile->version}, ".count($caddyfile->sites).' sites.');
        }

        foreach ($caddyfile->problems as $problem) {
            $this->error("Build refused: {$problem}");
        }

        return $caddyfile->buildable() ? self::SUCCESS : self::FAILURE;
    }

    private function diff(string $live, string $build): void
    {
        foreach (CaddyfileSiteDiff::compare($live, $build) as $row) {
            $this->line(sprintf('%-10s %s', $row['status'], $row['address']));

            foreach ($row['removed'] as $line) {
                $this->line("             - {$line}");
            }

            foreach ($row['added'] as $line) {
                $this->line("             + {$line}");
            }
        }
    }
}
