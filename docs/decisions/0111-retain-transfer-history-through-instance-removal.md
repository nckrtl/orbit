---
title: "ADR 0111: Retain completed transfer history through AppInstance removal"
sidebarTitle: "0111 Retain transfer history through removal"
description: "Proposed contract for transfer evidence and AppInstance removal."
---

# ADR 0111: Retain completed transfer history through AppInstance removal

Completed transfer history is retained after its AppInstance is removed, while unfinished or failed transfer history remains attached and blocks removal.

## Status

Proposed. This ADR supports ORB-369.

## Context

AppInstance transfers are durable recovery evidence. Their history must survive removal, but a foreign key that requires every history row to retain a live AppInstance prevents removal of an otherwise healthy instance. Discarding an unfinished or failed transfer would also discard recovery state silently.

## Decision

The Gateway makes `app_instance_transfers.app_instance_id` nullable. Successful transfer rows set that reference to null when their AppInstance is removed and remain queryable as retained evidence. A reserved, in-progress, or failed transfer keeps its reference and refuses AppInstance removal with `instance.transfer_incomplete`. Removal retries resume from the first incomplete removal step; a retry stalled at `row_deletion` repeats only the guarded row-deletion transaction.

## Rejected alternatives

- Delete all transfer rows: rejected because completed history and failed-transfer recovery evidence are valuable.
- Nullify every transfer row: rejected because it would silently discard the ownership and recovery boundary of an unfinished or failed transfer.
- Disable foreign keys: rejected because referential integrity remains required for attached recovery state.

## Consequences

- Removed instances have no dangling transfer references and completed history remains available.
- Operators must resolve or retain an unfinished or failed transfer before removing its instance.
- Historical rows with a null AppInstance reference are no longer navigable through the `appInstance` relation.

## Affects

- Components: apps/gateway
- ADRs: none
- Detail: [/reference/appinstance-removal](/reference/appinstance-removal)
- Verify: Gateway Pest removal and transfer-history tests
