# ORB-241 development record

Flow: `discovery`

Incus: not required. ORB-241 changes one automated CLI regression expectation and has no `incus` label. Discovery development only; isolated acceptance proof not run.

No approved feature plan exists. This is the maintenance repair for the retained main-correctness incident.

## Incident and classification

ORB-222 merged as `1bf4ac93e87b2a3d425fca09b6c319705f2278da` after ORB-224. Main maintenance then ran `composer test:affected` in `apps/cli` with TIA and exited 1. The retained failure log is `/home/nckrtl/orbit/.git/orbit-tia/v1/run-1g5pv2oe/apps-cli-tia.log`.

`tests/Feature/CommandSurfaceTest.php` already listed every current visible command, but its separate product-command count still expected 79. The four public deployment commands added by ORB-222 made the actual count 83. This is a source correctness failure on main, limited to `apps/cli`. The main merge hold remains valid until this reviewed repair merges and the original CLI TIA failure passes on main containing it.

## Candidate scope

Candidate `6dbfe058911a4a05587ad1fb35f9dbdfa1ae70b7` has tree `907768d053bfffb6cff34b144cfb043907cae9f9` and one commit over base `1bf4ac93e87b2a3d425fca09b6c319705f2278da`.

- The expected number of registered `App\\Commands\\` classes advances from 79 to 83.
- The exact visible-command list remains unchanged and still names all 83 public commands.
- The visibility assertion remains unchanged and still rejects any hidden Orbit product command.
- No command registration, name, option, behavior, dependency, or maintained documentation changes.

## Acceptance evidence

1. `cd apps/cli && composer test:affected` exited 0 in experimental TIA mode. TIA selected `tests/Feature/CommandSurfaceTest.php` from the one changed file and passed 680 tests with 3,919 assertions. The test observes exactly 83 registered `App\\Commands\\` classes and proves that each remains visible.
2. `cd apps/cli && composer check` exited 0. Its TIA guidance tests passed 13 tests with 255 assertions, then Rector, Pint, and Larastan passed.
3. `cd apps/cli && vendor/bin/pint --dirty --format agent` exited 0.
4. The exact-candidate root Builder gate passed all 15 validation, project-check, and affected-TIA commands across the five projects. Receipt: `/home/nckrtl/orbit/.git/orbit-checks/6dbfe058911a4a05587ad1fb35f9dbdfa1ae70b7/review-pa2wzck8/result.json`.
5. `git diff --check` passed, and the candidate stayed clean and unchanged during the Builder gate.

No Pest path, filter, group, suite, or `--no-tia` option was used.

## Documentation audit

Fixed:

- none

Reported:

- none

The issue has no `docs` label and changes no user-visible or maintained behavior. The only product diff is the existing CLI command-surface test expectation.

## Deviations and limitations

- Deviations: none.
- The implementation has no approved feature plan artifact. Review uses the Linear maintenance contract, this development record, the exact candidate, and the retained Builder receipt.
- A passing repair candidate does not clear the retained failure on main. After merge, maintenance must rerun the original CLI TIA check on main containing the repair and inspect the resulting cache publication before the orchestrator lifts the merge hold.
