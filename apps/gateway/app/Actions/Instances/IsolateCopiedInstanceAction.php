<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\Copy\InstanceCopyNames;
use App\Domain\Instances\Copy\InstanceCopyReferenceRewriter;
use App\Domain\Instances\DevelopmentInstanceCheckoutCopier;
use App\Domain\Instances\Environment\InstanceEnvironmentImporter;
use App\Domain\Instances\Environment\InstanceEnvironmentValidator;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copies stored configuration onto a new checkout and drops source paths and domains from it.
 */
final readonly class IsolateCopiedInstanceAction
{
    public function __construct(
        private DevelopmentInstanceCheckoutCopier $copies,
        private InstanceEnvironmentImporter $importer,
        private InstanceEnvironmentValidator $validator,
        private InstanceCopyReferenceRewriter $rewriter,
    ) {}

    public function execute(Instance $source, Instance $target): void
    {
        try {
            $this->isolate($source, $target);
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode === 'instance.copy_failed' && ($exception->details['copy_started'] ?? '') === '1') {
                throw $exception;
            }

            throw $this->failed($exception);
        } catch (Throwable $exception) {
            throw $this->failed($exception);
        }
    }

    private function isolate(Instance $source, Instance $target): void
    {
        $names = InstanceCopyNames::between($source, $target);
        $contents = $this->copies->readEnvironment($target);
        $parsed = $contents === null ? [] : $this->importer->parse($contents);

        DB::transaction(function () use ($source, $target, $names, $parsed): void {
            $stored = $this->stored($target->id);

            if ($stored === []) {
                $rewritten = [];

                foreach ($this->stored($source->id) as $key => $value) {
                    $rewritten[$key] = $this->rewriter->rewrite(
                        $value,
                        $names->sourceCheckout,
                        $names->targetCheckout,
                        $names->sourceDomain,
                        $names->targetDomain,
                    );
                }

                if ($rewritten !== []) {
                    $this->validator->validate($rewritten);
                }

                foreach ($rewritten as $key => $value) {
                    InstanceEnvironmentValue::query()->create([
                        'instance_id' => $target->id,
                        'env_key' => $key,
                        'env_value' => $value,
                    ]);
                }

                $stored = $rewritten;
            }

            $missing = [];

            foreach ($parsed as $key => $value) {
                if (array_key_exists($key, $stored)) {
                    continue;
                }

                $missing[$key] = $this->rewriter->rewrite(
                    $value,
                    $names->sourceCheckout,
                    $names->targetCheckout,
                    $names->sourceDomain,
                    $names->targetDomain,
                );
            }

            if ($missing !== []) {
                $this->validator->validate([...$stored, ...$missing]);

                foreach ($missing as $key => $value) {
                    InstanceEnvironmentValue::query()->create([
                        'instance_id' => $target->id,
                        'env_key' => $key,
                        'env_value' => $value,
                    ]);
                }
            }

            if (DatabaseConnectionTarget::query()->where('instance_id', $target->id)->lockForUpdate()->exists()) {
                return;
            }

            $attachments = DatabaseConnectionTarget::query()
                ->where('instance_id', $source->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($attachments as $attachment) {
                $connection = $attachment->databaseConnection;

                if (
                    $connection->driver === DatabaseDriver::Sqlite
                    && is_string($connection->path)
                    && $this->rewriter->isInsideCheckout($connection->path, $names->sourceCheckout)
                ) {
                    continue;
                }

                DatabaseConnectionTarget::query()->create([
                    'database_connection_id' => $attachment->database_connection_id,
                    'instance_id' => $target->id,
                    'prefix' => $attachment->prefix,
                ]);
            }
        });
    }

    /** @return array<string, string> */
    private function stored(int $instanceId): array
    {
        try {
            $values = [];

            foreach (InstanceEnvironmentValue::query()->where('instance_id', $instanceId)->orderBy('env_key')->lockForUpdate()->get() as $row) {
                $values[$row->env_key] = $row->env_value;
            }

            return $values;
        } catch (DecryptException $exception) {
            throw $this->failed($exception);
        }
    }

    private function failed(Throwable $exception): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.copy_failed',
            message: 'The copied Instance could not be made independent of its source.',
            status: 409,
            previous: $exception,
            details: ['copy_started' => '1'],
        );
    }
}
