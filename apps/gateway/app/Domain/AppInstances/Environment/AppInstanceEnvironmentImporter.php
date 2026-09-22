<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Domain\Shared\ResourceOperationException;
use Dotenv\Exception\InvalidFileException;
use Dotenv\Loader\Loader;
use Dotenv\Parser\Parser;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
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
            $parser = new Parser;
            $entries = $parser->parse($contents);
            $probedEntries = $parser->parse($contents."\n__ORBIT_IMPORT_COMPLETE__=1\n");

            // The parser drops unfinished multiline buffers. A quote-free probe must add
            // one entry; checking the count also prevents an earlier key from spoofing it.
            if (count($probedEntries) !== count($entries) + 1) {
                $this->fail();
            }

            $probe = $probedEntries[count($entries)];

            if ($probe->getName() !== '__ORBIT_IMPORT_COMPLETE__'
                || ! $probe->getValue()->isDefined()
                || $probe->getValue()->get()->getChars() !== '1') {
                $this->fail();
            }

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

            $repository = RepositoryBuilder::createWithNoAdapters()
                ->addAdapter(ArrayAdapter::class)
                ->make();
            $parsed = new Loader()->load($repository, $entries);
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
