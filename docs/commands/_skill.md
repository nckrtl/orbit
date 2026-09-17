---
name: "orbit"
description: "Operates an Orbit fleet through the orbit CLI, including Nodes, Clusters, Apps, App instances, Routes, environment values, Database connections, Processes, Schedules, deployments, and Doctor checks. Use when the user asks to create, deploy, inspect, move, repair, or remove anything that Orbit manages, or mentions Orbit or the orbit command."
---

# Orbit

Orbit runs applications on machines the user already owns. The `orbit` CLI sends each operational command to the Gateway, which changes the fleet and records the request as Activity. Read the command file before you run a command.

## Rules for every command

- Run `orbit gateway:status` first. A command that needs a Gateway fails when no Gateway profile is selected.
- Pass `--json` and parse the result. A failure returns `error.code`, `error.message`, and `error.request_id`.
- Find numeric IDs with the family's `list` command, such as `orbit app:list --json` and `orbit node:list --json`. Do not guess an ID.
- Ask the user before you pass `--yes` or `--force`. JSON output never gives consent, and `--force` means something different in each family.
- Check the exit status as well as the output. A nonzero status means the command did not succeed.
- After a refusal, read the error code in the command file. Retry only when the file says a retry resumes the work.
- Use `orbit activity:list --request-id=ID` to trace a request through the Gateway.
