<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionAttachmentResponse;

abstract class DatabaseAttachmentCommand extends DatabaseCommand
{
    protected function appInstanceSelector(): ?string
    {
        $selector = $this->option('instance');

        if (! is_string($selector) || $selector === '') {
            $this->renderGatewayFailure(
                'database.instance_required',
                'Instance ID or Route domain is required.'
            );

            return null;
        }

        return $selector;
    }

    protected function prefixOption(): ?string
    {
        $prefix = $this->input->getOption('prefix');

        if ($prefix === null) {
            return null;
        }

        if (! is_string($prefix) || preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            $this->renderGatewayFailure(
                'database.prefix_invalid',
                'Prefix must be an uppercase name of at most 32 characters.',
            );

            return null;
        }

        return $prefix;
    }

    protected function renderAttachment(DatabaseConnectionAttachmentResponse $attachment, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($attachment->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, [
            'Instance ID' => $attachment->appInstanceId,
            'Slug' => $attachment->slug,
            'Prefix' => $attachment->prefix,
            'Keys' => $attachment->keys === [] ? null : implode(', ', $attachment->keys),
            'Host' => $attachment->host,
            'Port' => $attachment->port,
            'Operation' => $attachment->operation,
            'Changed' => $attachment->changed ? 'true' : 'false',
            'Stored keys' => $attachment->keyCount,
            'Workload file' => 'unchanged',
            'Request ID' => $attachment->requestId,
        ]));

        return self::SUCCESS;
    }
}
