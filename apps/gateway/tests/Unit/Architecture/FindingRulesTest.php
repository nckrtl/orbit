<?php

declare(strict_types=1);

it('reports the three PHP finding rules and accepts allowed declarations', function (): void {
    $directory = sys_get_temp_dir().'/orbit-finding-rules-'.bin2hex(random_bytes(8));
    mkdir($directory.'/tests', 0777, true);

    $analyse = static function (string $path): array {
        exec(
            './vendor/bin/phpstan analyse --configuration=phpstan.neon --no-progress --error-format=json '
                .escapeshellarg($path).' 2>&1',
            $output,
            $status,
        );

        $result = json_decode(implode("\n", $output), true);

        return [$status, is_array($result) ? $result : [], implode("\n", $output)];
    };
    $checkClassifications = static function (string $path): array {
        exec('php ../../bin/check-classification-fakes '.escapeshellarg($path).' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    };

    try {
        $inlineVar = $directory.'/inline-var.php';
        file_put_contents($inlineVar, <<<'PHP'
<?php
final class InlineOverride
{
    public function forbiddenOverride(): void
    {
        /**
         * @var string $value
         */
        $value = 'value';
    }
}
PHP);
        [$status, $result, $output] = $analyse($inlineVar);
        $messages = array_values(array_filter(
            $result['files'][$inlineVar]['messages'] ?? [],
            static fn (array $message): bool => ($message['identifier'] ?? null) === 'orbit.inlineVarOverride',
        ));
        expect($status)->toBe(1, $output)
            ->and($messages)->toHaveCount(1)
            ->and($messages[0]['message'])->toBe('Inline @var overrides an inferred type inside a method body.');

        $strtotime = $directory.'/strtotime.php';
        file_put_contents($strtotime, <<<'PHP'
<?php
function forbidden_call(): int
{
    return strtotime('2026-01-01');
}
PHP);
        [$status, $result, $output] = $analyse($strtotime);
        $messages = $result['files'][$strtotime]['messages'] ?? [];
        expect($status)->toBe(1, $output)
            ->and($messages)->toHaveCount(1)
            ->and($messages[0]['identifier'])->toBe('disallowed.function')
            ->and($messages[0]['message'])->toBe(
                'Calling strtotime() is forbidden, Use Carbon parsing instead of strtotime().',
            );

        $allowed = $directory.'/Allowed.php';
        file_put_contents($allowed, <<<'PHP'
<?php
final class Allowed
{
    /** @var string */
    private string $property = 'value';

    /**
     * @param string $value
     */
    public function accept(string $value): string
    {
        // strtotime() in a comment is not a call.
        return $value.$this->property;
    }
}
PHP);
        [$status, , $output] = $analyse($allowed);
        expect($status)->toBe(0, $output);

        $outsideMethod = $directory.'/outside-method.php';
        file_put_contents($outsideMethod, <<<'PHP'
<?php
/** @var string $global */
$global = 'value';

function allowed_function(): string
{
    /** @var string $value */
    $value = 'value';

    return $value;
}
PHP);
        [$status, , $output] = $analyse($outsideMethod);
        expect($status)->toBe(0, $output);

        $badImport = $directory.'/tests/BadImportTest.php';
        file_put_contents($badImport, <<<'PHP'
<?php
use Laravel\Ai\Classification;
Classification::fake(function (): array {
    $response = [];
    return $response;
});
$decoy = '->preventStrayClassifications()';
// Classification::fake()->preventStrayClassifications() is only a comment.
PHP);
        [$status, $output] = $checkClassifications($badImport);
        expect($status)->toBe(1)->and($output)->toContain('preventStrayClassifications');

        $badFullyQualified = $directory.'/tests/BadFullyQualifiedTest.php';
        file_put_contents($badFullyQualified, <<<'PHP'
<?php
\Laravel\Ai\Classification::fake(function (): array {
    $response = [];
    return $response;
});
PHP);
        [$status, $output] = $checkClassifications($badFullyQualified);
        expect($status)->toBe(1)->and($output)->toContain('preventStrayClassifications');

        $badAlias = $directory.'/tests/BadAliasTest.php';
        file_put_contents($badAlias, <<<'PHP'
<?php
use Laravel\Ai\Classification as AiClassification;
AiClassification::fake(fn (): array => []);
PHP);
        [$status, $output] = $checkClassifications($badAlias);
        expect($status)->toBe(1)->and($output)->toContain('preventStrayClassifications');

        $badCapturedClosure = $directory.'/tests/BadCapturedClosureTest.php';
        file_put_contents($badCapturedClosure, <<<'PHP'
<?php
namespace Tests;
use Laravel\Ai\Classification;
$value = 'captured';
it('sample', function () use ($value): void {
    Classification::fake();
});
PHP);
        [$status, $output] = $checkClassifications($badCapturedClosure);
        expect($status)->toBe(1)->and($output)->toContain('preventStrayClassifications');

        $validCapturedClosure = $directory.'/tests/ValidCapturedClosureTest.php';
        file_put_contents($validCapturedClosure, <<<'PHP'
<?php
namespace Tests;
use Laravel\Ai\Classification;
$value = 'captured';
it('sample', function () use ($value): void {
    Classification::fake()->preventStrayClassifications();
});
PHP);
        [$status, $output] = $checkClassifications($validCapturedClosure);
        expect($status)->toBe(0, $output);

        $validAlias = $directory.'/tests/ValidAliasTest.php';
        file_put_contents($validAlias, <<<'PHP'
<?php
use Laravel\Ai\Classification as AiClassification;
AiClassification::fake(function (): array {
    $response = [];
    return $response;
})->preventStrayClassifications();
PHP);
        [$status, $output] = $checkClassifications($validAlias);
        expect($status)->toBe(0, $output);

        $validFullyQualified = $directory.'/tests/ValidFullyQualifiedTest.php';
        file_put_contents($validFullyQualified, <<<'PHP'
<?php
\Laravel\Ai\Classification::fake(function (): array {
    $response = [];
    return $response;
})->preventStrayClassifications();
PHP);
        [$status, $output] = $checkClassifications($validFullyQualified);
        expect($status)->toBe(0, $output);

        $falsePositive = $directory.'/tests/FalsePositiveTest.php';
        file_put_contents($falsePositive, <<<'PHP'
<?php
$example = 'Classification::fake()';
// \Laravel\Ai\Classification::fake() is only a comment.
PHP);
        [$status, $output] = $checkClassifications($falsePositive);
        expect($status)->toBe(0, $output);
    } finally {
        foreach (glob($directory.'/tests/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory.'/tests');
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
