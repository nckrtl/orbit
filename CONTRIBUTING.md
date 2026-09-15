# Contributing to Orbit

You can submit a pull request without a local Incus installation or a complete machine test. Run the relevant local checks and explain what you tested. Nick can perform the required Incus verification before merge.

## Set up

Use PHP 8.5, Composer 2, Git, OpenSSL, Python 3, and Bash 4 or newer. Keep the full monorepo: the CLI uses the SDK through a relative Composer path. Read the nearest `AGENTS.md` before changing a project.

```bash
git clone https://github.com/nckrtl/orbit.git
cd orbit
bin/bootstrap
```

Bootstrap installs all five projects and seeds compatible test caches. On macOS, use a current Bash; if Pest rejects the system temporary directory as an invalid namespace, run the command with `TMPDIR=/tmp`.

Work on a separate branch. The maintainer's `bin/worktree-create` flow expects a Linear identifier and GNU command-line tools. Public contributors can use an ordinary Git branch or worktree and run `bin/bootstrap` there.

## Check your change

Run commands from the changed project, such as `apps/cli`:

```bash
composer test:affected
composer check
```

Test impact analysis (TIA) selects affected tests. A cold cache can run the full project suite. Do not add a path or filter to a TIA command: that disables TIA selection. Add regression coverage for behavior changes and their important failure modes.

For maintained documentation, run these commands from the repository root:

```bash
composer docs-lint
composer docs-build
```

Commit an updated `docs/generated/context.json` when the build changes it. For Mintlify page or navigation changes, also run `npx mint validate` and `npx mint broken-links` from `docs/`.

GitHub checks are disabled. Include actual commands, results, and anything you could not verify in the pull request. A missing Incus environment does not prevent submission. The maintainer runs the exact-candidate local gate and arranges independent review and required machine verification before merging. See the [implementation loop](docs/reference/implementation-loop.md) for that delivery process.

## Submit and discuss

Keep the change focused. Describe the problem, the resulting behavior, and documentation impact. Link a public issue when one exists; contributors do not need access to the internal Linear backlog.

Use [GitHub issues](https://github.com/nckrtl/orbit/issues) for bugs and questions. Include the source commit, Orbit version, operating system, exact command, expected result, and observed result. Remove credentials, environment values, private keys, tokens, and personal data from logs before sharing them. Include a request ID and stable error code when available.
