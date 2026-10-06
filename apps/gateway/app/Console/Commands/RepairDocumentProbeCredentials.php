<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\ProjectDocuments\ProbeJournal;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class RepairDocumentProbeCredentials extends Command
{
    #[\Override]
    protected $signature = 'project-documents:probes:repair {record} {--access-key-id-file=} {--secret-access-key-file=}';

    #[\Override]
    protected $description = 'Replace credentials for one retained probe without changing its destination or key.';

    public function handle(ProbeJournal $journal): int
    {
        try {
            $id = $this->argument('record');
            $journal->repair($id, $this->credentialFile('access-key-id-file'), $this->credentialFile('secret-access-key-file'));
            $this->components->info('Probe credentials updated. The cleanup record remains retained.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->components->error('Could not repair probe credentials. Supply a tracked record and paired private credential files.');

            return self::FAILURE;
        }
    }

    private function credentialFile(string $option): string
    {
        $path = $this->option($option);
        if (! is_string($path) || ! is_file($path) || is_link($path) || (fileperms($path) & 077) !== 0) {
            throw new RuntimeException('Private credential files are required.');
        }
        $size = filesize($path);
        if (! is_int($size) || $size < 1 || $size > 1026) {
            throw new RuntimeException('Invalid credential file.');
        }
        $value = file_get_contents($path, false, null, 0, 1026);
        if (! is_string($value) || strlen($value) !== $size) {
            throw new RuntimeException('Could not read credential file.');
        }
        $value = preg_replace('/\r?\n\z/', '', $value) ?? $value;
        if ($value === '' || strlen($value) > 1024 || preg_match('//u', $value) !== 1) {
            throw new RuntimeException('Invalid credential file.');
        }

        return $value;
    }
}
