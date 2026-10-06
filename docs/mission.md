---
title: "Mission"
description: "What Orbit is for, where it stops, and the principles every decision must fit."
---

# Mission

Orbit turns the machines you own into an always-on network for developing and hosting your applications. Your agent operates it. You steer.

## What Orbit does

Orbit does two jobs on the machines you add.

- It manages your machines, called Nodes, and the applications on them. That covers development Instances, production releases, Routes, Processes, Schedules, and certificates.
- It runs planned work with coding agents through the optional [Tasks](/reference/tasks) extension. The work runs in isolated Instances and passes checks. It arrives as a pull request.

## Where Orbit stops

These boundaries keep Orbit small and neutral.

- Your agentic development environment (ADE) is where you think, plan, and steer.
- Orbit is where work runs. It has no IDE, chat, or planning interface. Plans arrive through the CLI or MCP.
- The plain-text editor for [Project Documents](/reference/project-documents) is a narrow exception for Project-owned notes. Agents use the same document API.
- Orbit manages only the machines and resources you add.
- Each Project defines its own way of working in its repository. The Orbit engine stays generic.

## Principles

Every architecture decision must fit these principles.

1. **Agents operate, humans steer.** Every action is a command that an agent can run and a human can read.
2. **One way, one name.** Each task has one supported path. Each concept has one term in the CLI, API, database, and documentation.
3. **No exceptions and no legacy.** Orbit refuses an unsupported combination instead of making it work. Orbit removes old paths instead of keeping them for compatibility.
4. **Deterministic first.** Code and checks decide what they can. Models judge only what code cannot.
5. **Lean.** A feature earns its place. Orbit deletes the parts that nothing uses.
6. **The documentation describes the present.** It is the current agreed truth. Orbit absorbs each decision into it once the decision is built.
7. **Security fits the real threat model.** Orbit adds no defense layer against an attacker who is already past the boundary.

See [Architecture](/architecture) for how the parts work together.
