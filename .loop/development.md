# ORB-364 development record

Flow: proof. Incus required. Independent preflight PASS artifact: `be8b54d68544caea3f5acade3e262402fd00aafc`.

## Implementation

The hibernator installer uses the existing ProtectedInput helper to materialize rendered units in mode-0600 temporary regular files. Fixed sudo install argv copies each file to its existing systemd destination. A finally block removes each temporary file on success and failure. Failed native commands retain their exact NodeProvisioningException and CommandResult; preparation or execution exceptions receive the existing error and unit-step identity.

No renderer, account-selection, policy, CLI, or harness changes.

## Acceptance evidence

1. Solo process 1740 ran the real converger on ORB-364 discovery attempt `b6628d58b33b24fd9b7f82c59abab321`, Ubuntu Resolute with install (uutils coreutils) 0.8.0. Initial, repeated, changed account/interval, and restoration phases all passed. Full installed contents matched renderers, both files were root:root:644, and the timer was enabled and active. Discovery used mounted uncommitted source; isolated acceptance proof will bind the committed candidate. Log: `/tmp/orb364-discovery-install.log` on Beast, retained under this artifact's evidence directory.
2. Gateway `composer test:affected` passed 3510 tests / 21899 assertions (1514 affected). The converger tests inspect bytes and mode while the source file exists, verify execution order and configured account, and assert cleanup. Log: `/tmp/orb364-gateway-tia.log`.
3. Four failure cases cover service/timer nonzero exits and service/timer execution exceptions. They assert failed step, error code, preserved result/cause, exact invocation count and temporary-file removal. These ran in the same Gateway TIA invocation.

Gateway `composer check` passed guidance (22 tests / 300 assertions), Rector, Pint (1317 files), and PHPStan. Log: `/tmp/orb364-gateway-check.log`. Read-only advisory inspection found no substantive code or fixture issue.

E2E `composer test:fresh` passed 1551 tests / 8642 assertions in 66.48s after the proof fixture was written. Log: `/tmp/orb364-e2e-fresh.log`.

## Documentation

None changed: the repair restores installation without changing documented behavior. The scoped audit found no drift in the affected installation statements. The existing uutils solution already documents the stdin overwrite failure and unaffected regular-file source.

## Proof

`.loop/proof/ORB-364.json` invokes `orb-364-repeatable-install.php` on a separate exact-candidate Gateway. It declares mutations and restores the normal units before general verification. PHP observations are disabled because this native Gateway-only action does not exercise all three required instrumentation surfaces. Broad static equivalence rules apply.

## Deviations and limits

None. Discovery is diagnostic evidence; it does not replace isolated proof, the exact-candidate Builder receipt, independent review or closeout. ORB-351 and the full CLI UX restoration remain incomplete.
