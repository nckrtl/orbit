<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Domain\Shared\ResourceOperationException;
use Dotenv\Exception\InvalidFileException;
use Dotenv\Parser\Parser;
use Throwable;

final readonly class AppInstanceEnvironmentImporter
{
    /**
     * @return array<string, string>
     *
     * @mago-expect analysis:mixed-assignment The dotenv package exposes an untyped parser boundary.
     */
    public function parse(#[\SensitiveParameter] string $contents): array
    {
        if (strlen($contents) > AppInstanceEnvironmentValidator::MaximumFileBytes) {
            $this->fail();
        }

        try {
            $entries = new Parser()->parse($contents);
            $seen = [];

            foreach ($entries as $entry) {
                $name = $entry->getName();

                if (array_key_exists($name, $seen)) {
                    $this->fail();
                }

                $value = $entry->getValue();
                if (! $value->isDefined()) {
                    $this->fail();
                }

                foreach ($value->get()->getVars() as $position) {
                    $tail = substr($value->get()->getChars(), $position);

                    if (preg_match('/\A\${([A-Za-z0-9_.]+)}/', $tail, $match) !== 1) {
                        $this->fail();
                    }

                    if (! array_key_exists($match[1], $seen)) {
                        $this->fail();
                    }
                }

                $seen[$name] = true;
            }

            $parsed = \Dotenv\Dotenv::parse($contents);
        } catch (InvalidFileException) {
            $this->fail();
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail();
        }

        $values = [];

        foreach ($parsed as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                $this->fail();
            }

            $values[$key] = $value;
        }

        return $values;
    }

    private function fail(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.import_invalid',
            message: 'The AppInstance environment file is invalid.',
        );
    }
}
