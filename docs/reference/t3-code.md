---
title: "T3 Code layer"
description: "How the Gateway signs in T3 Code devices as Nodes, shares workspaces through profiles, holds an admin session for each registered T3 server, and mints and revokes device pairings."
covers:
  - apps/gateway/app/Actions/T3/**
  - apps/gateway/app/Data/T3/**
  - apps/gateway/app/Domain/T3/**
  - apps/gateway/app/Infrastructure/T3/**
  - apps/gateway/app/Http/Controllers/Api/T3*.php
  - apps/gateway/app/Http/Requests/T3/**
  - apps/gateway/app/Models/T3*.php
---

# T3 Code layer

This page is for the person who builds the T3 Code client side, and for the Gateway maintainer. The Gateway gives T3 Code one central layer: a device signs in as its Node, sees every registered T3 server, and shares workspaces with the other devices of its profile. T3 traffic does not pass through the Gateway. A device talks to each T3 server directly over WireGuard.

The Gateway answers lists, profiles, and pairing. It mints T3 pairing links with an admin session that each T3 server hands it at registration, and it revokes the sessions it paired.

## Identity

A caller is the active Node that owns its WireGuard address, as for every other Gateway API call. Phones and laptops are Nodes too, without roles, much like an operator Node. A caller that is not an active Node gets `peer.identity_unknown` (403).

This slice has no grants. The T3 endpoints need no node access edge: every active Node may read and change every profile, register a T3 server, mint a pairing link, and revoke any Node's sessions.

## Profiles

A profile is a named set of shared settings, such as "Nick". A Node belongs to at most one profile. On first connect the device lists the profiles, lets the user pick or create one, and binds to it. Later calls read the binding from `GET /api/v1/t3/me`. Every Node sees every profile.

A profile holds one settings document. Its keys follow T3 Code's workspace store, so they stay camelCase:

```json
{
  "workspaces": [
    {
      "id": "ws-3f1c",
      "name": "Orbit",
      "color": "blue",
      "icon": "rocket",
      "image": null,
      "projectRefs": ["env-beast:project-1", "env-mini:project-2"],
      "projectKeys": ["orbit"]
    }
  ]
}
```

| Field | Rule |
| --- | --- |
| `workspaces` | Required list, at most 200. No other top-level key is accepted. |
| `id` | Required string, at most 100 characters, unique in the list. |
| `name` | Required string, at most 100 characters. |
| `color` | Required string, at most 32 characters. |
| `icon` | Required key; a string of at most 64 characters, or `null`. |
| `image` | Optional; `null`, or a PNG, JPEG, or WebP base64 data URL of at most 200,000 characters. |
| `projectRefs` | Optional list of `<environmentId>:<projectId>` strings, at most 500: one project on one server. |
| `projectKeys` | Optional list of T3 logical project keys, at most 500: a project on every server where it exists. |

A workspace covers its `projectRefs` and every project whose logical key is in `projectKeys`, as T3 Code combines them. T3 derives a logical key from the git remote by default, so devices that keep T3's default repository grouping agree on it.

The document has a version. A new profile starts at version 1 with no workspaces, and every replace adds 1. A replace must send the version the client last read. A stale version is refused with `t3.settings_version_conflict` (409), so two devices cannot overwrite each other. The client then reads again, merges, and retries.

## Registration

A T3 server registers from the Node it runs on. A job on that host issues an admin session for the Gateway with the stock T3 CLI, so the server runs unmodified upstream T3:

```bash
t3 auth session issue --label "Orbit Gateway" --token-only
```

The job sends that token as `admin_session`, with the server's environment id, its label, and the URL that devices use to reach it over WireGuard.

1. The Gateway reads `GET <url>/.well-known/t3/environment`. The served `environmentId` must equal `environment_id`, or the request fails with `t3.environment_mismatch` (422) before the session is sent.
2. It reads `GET <url>/api/auth/session` with the session as its bearer token. A session T3 does not accept fails with `t3.session_rejected` (502).
3. The session must carry `access:read` and `access:write`. `t3 auth session issue` grants both. A session without them fails with `t3.admin_scope_missing` (422).
4. The Gateway stores the session encrypted, with the expiry T3 reports. T3 sessions last 30 days by default.
5. It lists the server's client sessions and revokes every earlier `Orbit Gateway` session. A failure here does not fail the registration. This is why the job must issue the session with the label `Orbit Gateway`.

Registering again replaces the stored session. The job registers on each server start and before the session expires. An environment whose session expired shows `status: session_expired` and cannot mint pairings until it registers again.

## Pairing and revocation

A device asks the Gateway for a pairing link to one registered T3 server. The Gateway calls `POST <url>/api/auth/pairing-token` with its admin session and a unique label, such as `phone via Orbit k3j9x0qa`. T3 gives the session paired from that link the same client label. The Gateway records the pairing: the Node, the server, the T3 link id, the label, and the link's expiry.

The device opens the returned `pairing_url` and pairs as it does today, but without a manual step. The link is one-time and short-lived, so the device pairs right away.

To revoke, the Gateway lists `GET <url>/api/auth/clients`. It revokes each session whose label belongs to the Node's open pairings on that server with `POST <url>/api/auth/clients/revoke`, and it revokes each pairing link that was not used with `POST <url>/api/auth/pairing-links/revoke`. It never revokes its own current session. The device loses that server at once; its WireGuard access does not change.

## API

Every endpoint is under `https://gateway.orbit/api/v1/t3` and answers `{"data": ..., "meta": {"request_id": ...}}`. A failure answers `{"error": {"code", "message", "details"}}`. The generated [API reference](/api/overview) lists the same operations with their schemas.

| Method and path | Request | Response `data` |
| --- | --- | --- |
| `GET /me` | — | node, with `profile` `null` before a binding |
| `PUT /me/profile` | `{"profile_id": 1}` | node |
| `GET /profiles` | — | list of profile |
| `POST /profiles` | `{"name": "Nick"}` | profile (201) |
| `GET /profiles/{id}/settings` | — | settings |
| `PUT /profiles/{id}/settings` | `{"version": 3, "settings": {"workspaces": [...]}}` | settings |
| `GET /environments` | — | list of environment |
| `POST /environments` | `{"environment_id", "label", "url", "admin_session"}` | environment |
| `GET /environments/{environment_id}/pairings` | — | list of pairing, newest first |
| `POST /environments/{environment_id}/pairings` | — | `{pairing, pairing_url}` (201) |
| `POST /environments/{environment_id}/pairings/revoke` | `{"node_id": 4}`, optional | `{environment_id, node_id, revoked_sessions, pairings}` |

The objects:

```json
{
  "node": {"id": 4, "name": "phone", "wireguard_ip": "10.44.0.77", "profile": {"id": 1, "name": "Nick", "settings_version": 3, "updated_at": "2026-10-08T10:00:00+00:00"}},
  "profile": {"id": 1, "name": "Nick", "settings_version": 3, "updated_at": "2026-10-08T10:00:00+00:00"},
  "settings": {"profile_id": 1, "version": 3, "settings": {"workspaces": []}, "updated_at": "2026-10-08T10:00:00+00:00"},
  "environment": {"environment_id": "env-beast", "label": "beast", "url": "http://10.44.0.30:3773", "server_version": "0.9.1", "registered_by": "beast", "registered_at": "2026-10-08T09:00:00+00:00", "admin_session_expires_at": "2026-11-07T09:00:00+00:00", "status": "registered"},
  "pairing": {"id": 7, "environment_id": "env-beast", "node_id": 4, "node_name": "phone", "client_label": "phone via Orbit k3j9x0qa", "issued_at": "2026-10-08T10:00:00+00:00", "expires_at": "2026-10-08T10:05:00+00:00", "revoked_at": null}
}
```

`pairing_url` has T3's form, `<url origin>/pair#token=<token>`.

| Error code | Status | Meaning |
| --- | --- | --- |
| `peer.identity_unknown` | 403 | The caller is not an active Node. |
| `validation.failed` | 422 | The request breaks a rule; `details` names each field. |
| `t3.settings_version_conflict` | 409 | The settings changed since the sent version; `details.current_version` is the new one. |
| `t3.environment_mismatch` | 422 | `url` serves another environment. |
| `t3.admin_scope_missing` | 422 | The registered session is not an admin session. |
| `t3.session_expired` | 409 | The Gateway's admin session on that server expired. |
| `t3.server_unreachable` | 502 | The T3 server did not answer. |
| `t3.session_rejected` | 502 | T3 does not accept the admin session: it answered 401, or `/api/auth/session` reported it unauthenticated. |
| `t3.request_refused` | 502 | The T3 server refused the call with another status; `details` has its status and reason. |
| `t3.response_invalid` | 502 | The T3 server's answer did not have T3's shape. |

Every call is recorded as command activity with the calling Node. A registration records its environment id, label, and URL, never the admin session. A settings replace records only the version it replaced, because the document can hold workspace pictures.

## Why it works this way

The T3 Connect relay could also be self-hosted. It needs four outside services and puts Cloudflare in the traffic path, and it has no place for workspaces. The Gateway already knows every machine and runs on the private network, so it adds no new service.

The Gateway uses T3's own admin sessions and pairing instead of a new T3 auth scope. The host issues the admin session with the stock `t3` CLI, so servers run unmodified upstream T3, and the Gateway needs no SSH access to them. An admin pairing link would keep a long-lived token out of the request, but upstream T3 creates admin links only inside the server process. The cost is that the Gateway holds full admin access to every registered T3 server. On a private WireGuard network where the Gateway already manages every machine, that adds little new risk.

The Node is the identity because the Gateway already knows every WireGuard peer as a Node, phones included, and WireGuard binds each address to one key. Revocation happens only in T3 and leaves WireGuard access alone, so a revoked device keeps its other servers and Orbit access.

A Node belongs to one profile because each device is its own Node: a profile is how a phone and a desktop share one copy of the workspaces. Settings use a version check rather than last-write-wins, so an edit on one device is never lost silently to an older copy on another.
