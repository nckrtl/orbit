# Implementer self-check: next slice

Status: proposed follow-up. No checker or Composer command exists yet.

Build a local PHP test-quality checker that implementer agents can run before handing off changes. Its first version should catch a small set of demonstrable mistakes and explain each finding. It complements Pest, PHPStan, and this audit workflow; it cannot establish complete test value or prove code dead.

## First version

Use a PHP syntax tree and the installed parser where practical. Confirm the dependency is available in every supported invocation environment before choosing the host project. Do not use regex matches as blocking proof. Keep the check read-only, deterministic, offline, and independent of application boot or live infrastructure.

Start with candidate rules for:

- A direct self-comparison of the same stable variable or literal. Exclude expressions whose evaluation can change state or return a new value.
- An empty executable test body with no inherited expectation or applicable setup. Distinguish a declared pending test from an accidentally empty test.
- A trivially constant assertion that cannot observe application behavior. Do not label an entire test useless if its other assertions or exception expectations still enforce a contract.

Treat source-string checks, reflection, test names, mock-heavy tests, repeated fixtures, and missing explicit `expect()` calls as review signals until context supports a stronger conclusion. Recognize PHPUnit assertion methods, Pest higher-order tests and datasets, exception expectations, Mockery expectations, helper assertions, custom expectations, and architecture tests. If the checker cannot resolve a helper or expectation, report incomplete analysis rather than an assertion-free test.

Do not auto-delete or rewrite tests. Do not infer duplicate coverage from similar text. Defer mock correctness, unreachable negative cases, obsolete behavior, and dead-code decisions to the evidence ledger.

## Agent-facing result

Follow the [CLI design skill](../../designing-cli-commands/SKILL.md) when choosing the command and options. Support explicit files and a changed-files workflow; define the Git comparison base and include untracked test files. Report unsupported files and analysis failures separately from clean files.

Provide readable output and structured output with stable rule IDs, file and line, severity, evidence, and a suggested action. Define distinct outcomes for success, confirmed findings, and incomplete or failed analysis. A clean result means only that the supported rules found no issue in the reported scope.

Begin as an advisory self-check. Add a blocking gate only for rules validated against Orbit's existing suites and reviewed false positives. Do not hide the current suite behind a blanket baseline or require a mass cleanup to adopt the tool.

## Acceptance evidence

Each rule needs a realistic failing fixture and nearby valid controls, including indirect assertions, exception-only tests, mock expectations, datasets, and architecture tests where relevant. Demonstrate that a valid behavior test becomes flagged when changed into the targeted bad pattern, and clears after repair. Test parser errors and unknown constructs so the tool cannot report a false clean result.

Run the checker across all five PHP projects and manually review its findings before enabling it for implementers. Record false positives, known blind spots, and runtime. Evaluate whether repeated use improves findings before expanding the rule set. Keep non-PHP adapters and semantic duplication analysis as separate follow-ups.
