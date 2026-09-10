<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Domain\Shared\ResourceOperationException;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Dotenv\Parser\Parser;
use Throwable;

final readonly class AppInstanceEnvironmentImporter
{
    /**
     * @return array<string, string>
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
                    $tail = mb_substr($value->get()->getChars(), $position, null, 'UTF-8');

                    if (preg_match('/\A\${([A-Za-z0-9_.]+)}/', $tail, $match) !== 1) {
                        $this->fail();
                    }

                    if (! array_key_exists($match[1], $seen)) {
                        $this->fail();
                    }
                }

                $seen[$name] = true;
            }

            $parsed = Dotenv::parse($contents);
        } catch (InvalidFileException) {
            $this->fail();
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail();
        }

        return $this->stringValues($parsed);
    }

    /**
     * @param  array<array-key, mixed>  $parsed
     * @return array<string, string>
     */
    private function stringValues(array $parsed): array
    {
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
