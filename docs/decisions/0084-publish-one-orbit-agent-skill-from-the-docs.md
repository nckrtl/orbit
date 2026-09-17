---
title: "ADR 0084: Publish one Orbit Agent Skill from the documentation"
sidebarTitle: "0084 Publish one Orbit Agent Skill from the documentation"
description: "Proposed. One source per CLI command renders the CLI pages and one orbit Agent Skill, and skill:install downloads the skill."
---

# ADR 0084: Publish one Orbit Agent Skill from the documentation

Orbit keeps one source file per CLI command under `docs/commands`. A generator renders the CLI reference pages and one Agent Skill named `orbit` from those sources, and the documentation site serves the skill as one JSON bundle. `orbit skill:install` downloads that bundle into a project or global agent skill folder, so installed skills follow the published documentation instead of the installed CLI version.

## Status

Proposed.

## Context

Coding agents drive Orbit through the CLI. The [CLI reference](/cli/overview) describes every command, argument, and option, but it does not tell an agent which command to choose, how to find the IDs a command needs, how to check the result, or what to do after a refusal. An Agent Skill carries that procedure, and Codex and Claude Code both load skills from a folder that holds a `SKILL.md` file. A skill written apart from the CLI pages repeats them, and every command change then needs the same edit in two places.

The [Agent Skills specification](https://agentskills.io/specification) and Anthropic's authoring guide require a `name` that matches the skill folder and a `description`, keep `SKILL.md` under 500 lines, and keep reference files one level deep from `SKILL.md`, because an agent can preview only the top of a file that another reference file links. Codex reads project skills from `.agents/skills` and global skills from `~/.agents/skills`. Claude Code reads project skills from `.claude/skills` and global skills from `~/.claude/skills`, and it does not read `.agents/skills`.

The hosted documentation serves each page as Markdown at its `.md` URL, but that rendering drops the frontmatter, adds a preamble, and rewrites links. It serves JSON files from the documentation folder unchanged. The CLI writes local files only under `$ORBIT_HOME` today, and [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk) reserves the `install` and `remove` pair for Tools.

## Decision

- Every CLI command must have one source file at `docs/commands/<family>/<slug>.md`, where the slug is the command name with colons replaced by hyphens, such as `docs/commands/instance/instance-deploy-step-create.md`. A source names every argument and option of the command, and a source links another command by relative file path.
- `docs/commands/<family>/_family.md` must hold the family page prose and the command order, and `docs/commands/_skill.md` must hold the skill description and the rules that apply to every command.
- `bin/docs-commands` must render `docs/cli/<family>.mdx`, `docs/skills/orbit/SKILL.md`, `docs/skills/orbit/references/<family>/<slug>.md`, and `docs/skills/orbit.json` from those sources. The rendered files are committed, and `bin/docs-commands --check` runs in the `apps/docs` checks.
- Orbit must publish exactly one Agent Skill, named `orbit`. `SKILL.md` must be the index, grouped by the CLI tab groups, and must link every command file directly.
- The bundle must hold the path, SHA-256 digest, and contents of every skill file.
- The documentation site must describe the skill on one page in the CLI tab and must not render the skill files as pages.
- The CLI test suite must fail when a product command has no source, a source names no product command, or a source omits an argument or option.
- The CLI must add a `skill` family with `skill:install` and `skill:remove`. This extends the `install` and `remove` pair of ADR 0071 to the Orbit skill.
- `skill:install` must download the bundle from the published documentation, verify each digest, and replace the whole `orbit` skill folder at each target.
- `--codex` must target `~/.agents/skills/orbit` and `--claude` must target `~/.claude/skills/orbit`. Both options may be combined.
- Without a target option, `skill:install` must target `.agents/skills/orbit` in the current directory when `.agents` exists, and `.claude/skills/orbit` when only `.claude` exists. When both exist, it must install into `.agents/skills/orbit` and link `.claude/skills/orbit` to it unless that path already resolves to the installed folder. When neither exists, it must create `.agents/skills/orbit`.
- The CLI may write outside `$ORBIT_HOME` only at these skill targets.

## Rejected alternatives

- Embed the skill in the CLI binary: rejected so that skill fixes reach agents without a CLI release. The installed skill can describe a newer CLI than the one installed.
- A skill written apart from the CLI pages: rejected because the same command would be described twice, and nothing would hold the two texts together.
- A Skills tab that renders every command file: rejected because the CLI tab already shows the same text, and a second tree of the same pages doubles the site without adding content.
- One skill for each command family: rejected because every agent session would load twenty skill descriptions, and the install would manage twenty folders.
- A `SKILL.md` index in each family folder: rejected because it puts command files two links below the root `SKILL.md`, and the specification defines one `SKILL.md` per skill.
- Command signatures with colons as file names: rejected because a Markdown link such as `node:add.md` parses as a URL with the scheme `node`, and Windows does not allow colons in file names.
- Download from the hosted `.md` pages or raw GitHub files: rejected because the `.md` rendering changes the files, and raw files need a separate file list and one request per file.

## Consequences

- A command is described once. People read it on the family page and agents read it as one file.
- An agent finds the right command from one index and reads one self-contained file for it.
- Every new, renamed, or removed command needs its source file in the same change, and the rendered pages must be regenerated before the checks pass.
- The family pages under `docs/cli` are generated, so a fix to a command section is made in its source.
- A skill installed from the documentation can name a command that the installed CLI does not have yet. Upgrading the CLI or reinstalling the skill resolves the difference.
- Replacing the whole folder discards local edits inside an installed `orbit` skill.

## Affects

- Components: apps/cli, apps/docs
- ADRs: extends [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk)
- Detail: docs/cli/agent-skill.mdx
- Verify: `composer docs-lint`; `bin/docs-commands --check`; the CLI command-surface test that checks the command sources; CLI tests for `skill:install` targets, digest refusal, and the symlink
