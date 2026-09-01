# Mission

Orbit is an AI-first, all-in-one tool for local app development, production
hosting, and day-to-day fleet maintenance. It can be fully operated by your AI
agent, so you can focus on building products without operational distractions.

## Why Orbit exists

Modern development tools are good at individual jobs. One tool may handle local
development, another may deploy an application, and another may monitor a
server. The challenge starts when those tools need to work together. They often
have separate logins, separate settings, and different concepts of the same
application.

Orbit connects those stages. It keeps track of your applications and machines,
gives them a private network, and lets you manage them through the same CLI.
You can see what changed without rebuilding the story from several dashboards
and configuration files.

## How Orbit helps

The Gateway is the center of an Orbit setup. It remembers which machines and
applications belong to Orbit and coordinates changes across them. The CLI is
how people and coding agents talk to the Gateway. Managed Nodes do the actual
work, such as running an application or routing traffic.

Orbit connects its machines through a private network. Normal operations go
through the Gateway, so you do not need public SSH access to every machine.

## Built for people and agents

People and coding agents use the same commands. Human output should be easy to
read, while structured output gives automation reliable data to work with.
Orbit keeps a history of changes so you can understand what happened when
something goes wrong.

Agents can help with routine development and operations, but they do not get
unrestricted access to every machine. Their actions stay focused, visible, and
testable.

## What Orbit manages

Orbit manages applications, development environments, production servers,
routes, processes, tools, settings, metrics, networking, and certificates.
These features grow over time, but they all belong to the same view of your
infrastructure.

Orbit only manages machines and resources you explicitly add. You still choose
and operate the infrastructure. When a change depends on Linux itself, Orbit
tests it on temporary machines before relying on it.

To see how the main parts work together, continue with
[Architecture](architecture.md).
