<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Firewall\UfwStatusParser;
use App\Infrastructure\Processes\CommandResult;

/**
 * Compares `ufw status numbered` output with managed rules for a Doctor probe. An inactive firewall
 * never matches, and an unreadable status makes the inspection fail.
 */
final readonly class UfwManagedRulesCheck
{
    public function __construct(
        private UfwStatusParser $parser = new UfwStatusParser,
    ) {}

    /** @param list<UfwManagedRule> $rules */
    public function matches(CommandResult $result, array $rules): bool
    {
        if (! $result->succeeded() || $result->truncated) {
            throw new DoctorInspectionException;
        }

        if (preg_match('/\AStatus:\s+inactive\s*\z/i', $result->stdout) === 1) {
            return false;
        }

        if (preg_match('/\AStatus:\s+active\s*$/mi', $result->stdout) !== 1) {
            throw new DoctorInspectionException;
        }

        return array_all(
            $rules,
            fn (UfwManagedRule $rule): bool => $this->parser->ownership($result->stdout, $rule->shape) === UfwRuleOwnership::Exact,
        );
    }
}
