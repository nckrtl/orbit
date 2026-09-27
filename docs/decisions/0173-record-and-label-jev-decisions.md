---
title: "ADR 0173: Record and label every Jev decision"
sidebarTitle: "0173 Jev decision records"
description: "Proposed. Store every Jev request and answer in durable records, label decisions from deterministic outcomes, and report quality and calibration."
---

# ADR 0173: Record and label every Jev decision

Orbit stores every Jev call and its answers in a durable dataset, then labels answers only from deterministic evidence. A Gateway report measures failures, accuracy, errors, confidence calibration, and latency so Orbit can evaluate Jev and build a Laya dataset.

## Status

Proposed.

## Context

Every current Jev call uses `App\\Infrastructure\\Tasks\\Jev::classify`. The live call is `LaravelAiTaskBriefCoverage::missing`, which `TaskScheduler` runs before committing the group's last subtask. A missing answer fails the `brief_coverage` rubric item and blocks approval with a reminder. Jev receives the group and subtask briefs and the pull request change list, not code; this is a coverage judgment, not a correctness check.

`TaskSessionClassifier` and `LaravelAiTaskSessionClassifier` are bound in `TasksServiceProvider`, but production application code does not inject or resolve them. References in tests stub the classifier; they are not production callers. Its `outcome` and `next_action` Choices, confidence-threshold escalation, and related calibration behavior described by ADR 0117 are therefore not active behavior. This ADR retires that unused classifier contract, its `ORBIT_TASKS_JEV_CONFIDENCE_THRESHOLD` configuration, and those claims in the Tasks reference. ADR 0117's role-specific blocked-question judgment and its threshold/calibration procedure do not describe current behavior; the reference keeps only behavior actually in use. This does not alter the live `brief_coverage` decision.

The Gateway has logged TypeSafe `503 overloaded` failures (2026-09-21). Today Orbit stores neither the question and input nor the answer, probabilities, model, latency, or failure for a Jev call. It cannot distinguish provider failures from poor decisions, evaluate confidence calibration, or retain examples for Laya.

Laravel AI v1.0.0 represents a Boolean answer with the probability that the answer is true. It does not provide a separate provider-confidence value for Boolean answers. A Choice answer includes a probability for each option and a separate nullable provider-confidence value. These fields must not be conflated: the probability assigned to the selected answer is the value used to calibrate predictions.

## Decision

The Gateway writes exactly one `jev_decisions` row for each Jev call, including calls that fail. The call row stores its purpose, subject identifiers (group, subtask, and agent thread when the call concerns them), serialized question definitions, the input state sent to Jev with secrets redacted, per-question answers, provider model identifier when returned, elapsed time, and a sanitized error code on failure. Each question definition includes its type, options, and criteria. The original request and answer fields do not change when labels are added.

For each answer, store the provider's returned probability distribution and nullable `provider_confidence` separately from `selected_answer_probability`. For a Boolean answer, store `P(true)` and `P(false) = 1 - P(true)`; set `selected_answer_probability` to `P(true)` when the answer is true and `P(false)` when it is false. The SDK supplies no separate provider-confidence value for a Boolean answer, so `provider_confidence` is null. For a Choice answer, store the provider's probabilities and nullable confidence as returned; `selected_answer_probability` is the probability assigned to the selected option. Do not substitute Choice confidence for the selected-option probability. If the selected option has no valid probability, preserve the answer and provider confidence but leave `selected_answer_probability` null. Calibration excludes answers with a null selected-answer probability; Boolean answers returned by the live coverage classifier have the probability needed for calibration.

Records exclude credentials, secrets, and raw provider error bodies. Error codes are stable, bounded identifiers, not exception messages. Preserve records indefinitely as Orbit's training and evaluation dataset; routine retention cleanup does not expire them.

### `brief_coverage` evidence and labels

At the coverage call, store the final approval comment identifier and digest of its `pull_request.changes` list. At merge, store the GitHub pull request number, merge commit SHA and time, and the pull request body's `## Changes` list snapshot and digest. The merged pull request body is the authoritative merge-time snapshot. A line is an approved line still present at merge only when its normalized text appears in both the final approval's change list and the merge-time `## Changes` list. Lines added or altered after approval do not count as approved lines.

Match change-list lines to subtasks mechanically. Normalize Unicode with NFKC, case-fold, replace each run of punctuation or symbols with a space, and collapse whitespace. A line names a subtask only when the normalized full subtask title appears as a contiguous sequence of whole tokens. Ignore the summary and breaking-changes sections. The match is known only when the title is unique within the group and exactly one approved merge-time line matches it. A duplicate title, multiple matching lines, missing snapshot, unavailable approval record, or unverifiable merge body makes that subtask's outcome unknown. Do not ask a model or reviewer to resolve an ambiguous match.

Orbit does not detect whether a change after the pull request merge fixes a coverage gap. The labeler does not read commit history or commit-message trailers.

Use mutually exclusive question labels for each Boolean answer when the merge-time outcome is known:

| Jev answer | Deterministic evidence | Question label |
| --- | --- | --- |
| Missing / false | Exactly one approved merge-time change line names the subtask | False negative |
| Missing / false | No approved merge-time change line names the subtask | Unlabeled |
| Covered / true | Any change-list evidence | Unlabeled |

Only a missing answer with exactly one named approved merge-time line receives a question-level `false_negative` label. Covered answers, missing answers without that evidence, Jev failures, unmerged or closed pull requests, and unknown evidence remain unlabeled. Store label provenance with each labeled answer: rule version, question and task IDs, approval comment ID and list digest, pull request number, merge SHA and time, merge-body digest, and matching change line.

Keep the brief's call-level label distinct from question labels. When every answer in a `brief_coverage` call says covered and its pull request merges, label the call `correct`. This label records only that the call's covered outcome reached a merge; it does not validate individual answers against the change list. Calls with mixed answers do not receive this all-covered call label.

### Report

A Gateway Artisan command, `orbit:tasks:jev-report`, reports each purpose over the selected record set. It includes call count, failed calls, labeled share, per-question accuracy, false-positive and false-negative counts, confidence calibration, and p50/p95 call latency. Labeled share is the fraction of calls with at least one labeled question or a call-level label. Accuracy and false-positive/false-negative counts use only labeled questions; “covered” is the positive class. Call-level labels are reported separately and do not enter these question metrics.

Calibration groups labeled questions with a non-null `selected_answer_probability` into fixed buckets `[0,.5)`, `[.5,.6)`, `[.6,.7)`, `[.7,.8)`, `[.8,.9)`, and `[.9,1]`. For each bucket, report the observed accuracy: the fraction of question labels marked correct. Do not use `provider_confidence` as the calibration probability. Latency percentiles include calls with a measured duration, including failures; unavailable latency is excluded. A purpose with no eligible observations reports null rather than a misleading zero.

## Rejected alternatives

- Log only Jev's final choice: without its question, options, criteria, and input state, Orbit cannot reproduce what the model judged or distinguish input defects from model errors.
- Label decisions with a second model: model judgments cannot provide an independent, deterministic evaluation set.
- Retain records for a short window: expiration would destroy the dataset needed for evaluation and training.
- Keep the unused session classifier and its threshold documentation: that would continue to describe behavior no production caller invokes and preserve dead configuration.

## Consequences

- Jev calls become auditable and provide a durable, labeled evaluation set for considering Laya.
- A missing answer earns `false_negative` only when the approved change list still names the task at merge. A merged pull request earns its call a separate `correct` label when all answers were covered. Orbit does not detect whether a change fixes a coverage gap.
- Ambiguous or incomplete merge evidence stays unlabeled; reports expose that limit rather than guessing.
- The Gateway owns the record schema, secret redaction, label provenance, retention, and report semantics.
- Permanent retention requires strict exclusion of secrets and raw provider errors.
- Removing the dead classifier retires the ADR 0117 blocked-question `outcome`/`next_action` Choices, role-specific evidence judging, confidence-threshold escalation, and live calibration claims. The active gate that checks brief coverage keeps its current behavior.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0117](/decisions/0117-judge-the-blocked-question-on-role-evidence)
- Detail: [Tasks](/reference/tasks)
- Verify: `composer docs-build` and `composer docs-lint`
