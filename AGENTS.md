# Orbit Monorepo

Orbit contains five separate Composer projects: CLI, Gateway, Docs, PHP SDK, and the Incus E2E harness. Read the nearest nested `AGENTS.md` before changing a project. Maintained documentation lives under root `docs/`; `apps/docs` contains its tooling.

## Feature contributions

Follow the [contributor guide](docs/contributor-guide.md): **architecture → documentation → implementation → complete PR → review → maintainer approval → merge**.

- Check existing ADRs and propose significant architectural changes in the feature PR.
- Write documentation before coding. Keep the code, tests, documentation, and ADRs consistent.
- Keep the PR focused on the feature, including any harness changes it needs.
- Request maintainer review when the feature is complete.
- Orbit's independent reviewer checks the code and reproduces the feature on Incus. Record the reviewed commit, environment, results, and limitations.
- Merge requires passing CI, resolved findings, independent code and Incus review, and maintainer approval.

## Skills

Choose the entry point for the work at hand.

| Task | Skill |
| --- | --- |
| Shape a feature and prepare its ADRs and documentation | [grill-with-docs](.agents/skills/grill-with-docs/SKILL.md) |
| Hand a feature to an Orbit planner when the operator asks for Orbit | [implementing-in-orbit](.agents/skills/implementing-in-orbit/SKILL.md) |
| Build the feature and submit a complete PR | [developing-features](.agents/skills/developing-features/SKILL.md) |
| Review a proposal or completed PR | [reviewing-pull-requests](.agents/skills/reviewing-pull-requests/SKILL.md) |
| Merge an approved PR | [merging-pull-requests](.agents/skills/merging-pull-requests/SKILL.md) |

For focused documentation work, use [writing-documentation](.agents/skills/writing-documentation/SKILL.md). For CLI design and audits, use [designing-cli-commands](.agents/skills/designing-cli-commands/SKILL.md). For real terminal recordings, use [verifying-cli-output](.agents/skills/verifying-cli-output/SKILL.md). Project guidance supplies coding and testing conventions.

## Checks and resources

Run `composer test:affected` and `composer check` in each changed project. Confirm that the tests covering the feature ran. CI checks all five projects; root `composer check` also runs checks across projects locally.

For documentation changes, follow `writing-documentation` and run `composer docs-lint`. Include generated context updates when `composer docs-build` changes `docs/generated/context.json`.

Use Incus machines allocated to the task. Preserve other work and follow resource cleanup safeguards. A known correctness failure on main holds unrelated merges until a reviewed fix is verified on main.

## Writing and knowledge

Write for human readers. Use common words, short sentences, and clear actors. Focus instructions on useful actions and required results. Keep detailed tool procedures in references.

Put architectural rationale in `docs/decisions`, stable reference material in `docs/reference`, and reusable lessons in `docs/solutions`. Add a page when it has lasting value. Keep contributor instructions in contributor guidance.

Direct user instructions take precedence over repository rules. Platform and safety requirements still apply.
