---
title: "Reduce Incus VM costs on ZFS"
description: "Measured ways to reduce prepared topology storage, dependency-copy writes, and VM memory without adding runtime controllers."
covers:
  - apps/e2e/config/e2e.php
  - apps/e2e/app/E2E/IncusHost.php
---

# Reduce Incus VM costs on ZFS

## Problem

Each Orbit proof needs its own [Incus topology](/reference/incus-topologies). ZFS makes the VM disk copies cheap. The prepared image contents, new writes inside each guest, and each VM's memory allocation still use resources.

## Cause

Incus clones the prepared snapshots with native ZFS clones. The test pool already uses LZ4 compression and sparse 16 GiB root volumes, with no space reservation. Copying another VM does not copy every disk block. The guest root filesystem is ext4, which does not support file reflinks. A [dependency copy](/domains/applications#dependency-copy) inside the guest writes the copied data again.

The original prepared Gateway and app-prod guests held Docker images despite running no Docker containers. Each Node had the same 2 GiB memory budget, although their workloads differ.

## Improvements, in priority order

The harness cleans caches at the end of convergence and uses fixed memory limits when it creates or clones Nodes. The filesystem change remains a candidate for follow-up work.

| Priority | Candidate | Measured benefit | Added complexity |
| --- | --- | --- | --- |
| 1 | Remove unused Docker images and cached package archives during preparation of roles that do not need them, then trim the guest | Gateway referenced disk data fell from 6.55 GiB to 1.98 GiB; app-prod fell from 7.07 GiB to 2.48 GiB. All 25 readiness checks passed. | One preparation step. Development, database, and Metrics Nodes keep their warm images. |
| 2 | Size memory for each Node: Gateway 1.5 GiB, app-dev 2 GiB, app-prod 1 GiB | A three-Node topology has a 4.5 GiB budget instead of 6 GiB, a 25% reduction. The workload and all 25 readiness checks passed after reboots. | Three fixed values in the existing recipe. No memory controller. |
| 3 | Use a reflink-capable filesystem for development checkouts | Three copies of a real Laravel dependency tree used 19.1 MiB on XFS, against 222.0 MiB on ext4. | A base image change or a workload disk with its own snapshot and cleanup lifecycle. |

### Clean the prepared guests

The Gateway and app-prod experiments removed unused Docker images and ran `apt-get clean`, then `fstrim /`. They kept the installed tools, service configuration, application data, and running services. Together, their referenced disk data fell by about 9.2 GiB. Docker's logical reclaimed-space report differed from the ZFS result, so measure the ZFS volume too.

The `compact.storage` convergence step does this during preparation of an owned guest, before the next snapshot. Nodes with `app-dev`, `database`, or `metrics` keep their Docker images. Other Nodes prune images that no container references. Every Node clears cached APT archives, flushes root filesystem deletions with `sync -f /`, and trims its root, skipping unsupported discard. The flush lets discard reach blocks whose deletions were still pending. Removing an image makes a later use of that image download it again.

Trimming a clone does not free blocks that its parent snapshot still references. The experiment reduced the clone's referenced data. It did not remove the promoted generation or prove an equal reduction in total pool usage. Keep the existing generation retention and lease checks when adopting a smaller prepared generation.

### Keep memory headroom

The passing profile ran 120 HTTPS requests at concurrency four: 60 Gateway health requests and 60 requests to the production Laravel application. It also ran development migration-status and Composer dry-run commands, then the full topology verifier. The profile passed again after the app-prod image cleanup. Across both runs, the sampled minimum available memory was 487 MiB on Gateway, 517 MiB on app-dev, and 279 MiB on app-prod.

| Gateway | app-dev | app-prod | Result |
| --- | --- | --- | --- |
| 2 GiB | 2 GiB | 2 GiB | Workload and 25 readiness checks passed. |
| 1 GiB | 1.5 GiB | 1 GiB | HTTP requests and simple commands passed. Readiness failed. The kernel killed PHP on Gateway and MySQL on app-dev. |
| 2 GiB | 2 GiB | 1 GiB | Workload and 25 readiness checks passed after rebooting all three VMs. |
| 1.5 GiB | 2 GiB | 1 GiB | Workload and 25 readiness checks passed after rebooting Gateway at its smaller size. |

An idle-memory reading did not predict the failed profile. The verifier starts several commands together, and the database Node needs room for those commands and its services. Keep app-dev at 2 GiB. A blanket 1 GiB or 1.5 GiB setting is not supported by these results.

Configured memory is a limit, not a measurement of resident host memory. The 25% saving is the configured topology budget. Resident memory depends on the pages each guest has touched.

Production request medians stayed near 56–58 milliseconds across the passing profiles. Gateway tail latency varied. In a warm control, Gateway's 95th percentile was 0.938 seconds at 2 GiB and 0.613 seconds at 1.5 GiB. These small samples do not show a consistent slowdown from the smaller budget. They do not establish a latency guarantee.

### Use guest reflinks where they pay

The copy experiment ran the shipped `RemoteInstanceDependencyCopier::Program` as the managed user. Each filesystem got three copies of each source. Every copied file and relative link matched. A changed target file left its source unchanged. `filefrag` showed shared XFS extents after the change.

| Source | ext4 copy space, three copies | XFS copy space, three copies | Median ext4 copy | Median XFS copy |
| --- | --- | --- | --- | --- |
| Real Laravel `vendor`, 8,850 files and about 46 MiB | 222.0 MiB | 19.1 MiB | 0.722 s | 0.553 s |
| Synthetic `vendor` and `node_modules`, about 260 MiB and 4,002 regular files | 815.4 MiB | 7.1 MiB | 0.460 s | 0.204 s |

The real tree saved about 91% of copy space and 0.17 seconds per copy. The larger files saved over 99% of copy space. These are guest filesystem allocation changes; they exclude the source and the filesystem's fixed overhead. Copy times exclude the following flush and content checks.

The host's VM volume also grew much less during the XFS copies. That supports the space result, though background writes and ZFS transaction timing make the guest filesystem comparison the clearer measurement.

The experiment used a temporary 2 GiB XFS loop filesystem inside app-dev. This proves file-copy behavior. It does not prove a managed workload disk, reboot mounting, or inclusion of another disk in the topology's coordinated snapshots. Do not turn the loop image into a permanent feature. Prefer one prepared filesystem and the existing copy program if a follow-up can keep the lifecycle simple.

## Existing settings to keep

Keep native Incus ZFS cloning, LZ4 compression, and sparse root volumes. They already provide the main VM disk saving. Shrinking the advertised 16 GiB disk does not reclaim 16 GiB of reserved space, because there is no such reservation.

Guest block discard is supported, and `fstrim.timer` is enabled on all three Nodes. A trim before any cleanup released only tens of MiB of referenced data. There is no evidence for adding another trim scheduler. A trim after deleting large caches is useful.

## Verification and limits

The experiments ran on 2 October 2026 with Incus 6.0.5, host ZFS on the `fast` pool, and Ubuntu 26.04 guests. The running source was `73eb6cf7fb26d615faa01c8a3f254538901bd090`. The leased topology was `ZFS-1`, attempt `a7e3ebd8aaa0789e987e2c49f132d4b3`, from prepared generation `4240070b4a8c-ba4d06c0864f`. Acquisition took 57.9 seconds.

Use the [Incus proof workflow](/reference/incus-topologies#commands) to reproduce the measurements. Record guest filesystem allocation, ZFS `used` and `referenced`, clone `origin`, configured memory, guest memory pressure, kernel out-of-memory events, and the readiness result. Allow a ZFS transaction to commit before reading its accounting. Keep file-content and write-isolation checks separate from copy timing.

The evidence labels include `bench-ext4`, `bench-xfs`, `prune-gateway`, `prune-app-prod`, `baseline-workload`, `smaller-workload`, `smaller-inventory-gateway`, `smaller-inventory-app-dev`, `prod-small-workload`, `balanced-workload`, `balanced-repeat-workload`, `latency-high-workload`, `latency-low-workload`, and `bench-cleanup`. The research bundle holds their programs, outputs, host measurements, and verifier results outside the repository.

### Implementation verification

Runtime verification used `315357731896000fb9c2fb258058d49df5f2d214`. The repository-wide `composer check` gate passed all 40 checks across the five projects. It ran all 8,879 Gateway tests and 56,582 assertions, and all 1,707 harness tests and 9,596 assertions. The gate ran in an accessible clone, with PHPStan's agent output hints disabled so the existing JSON-output tests received ordinary CI output. Formatting, static analysis, affected tests, and documentation lint passed. GitHub CI passed on this commit.

The owned `ZFS-1` storage proof used `f6b0041574345f7dfb8b5510510273613a516a19`, attempt `379d22953787821f66f8df65ad1bf9b5`. It passed all 25 readiness checks and the 120-request workload at the new limits. It reported no out-of-memory events or failed services. The implementation cleanup reduced Gateway referenced data from 6.59 GiB to 1.978 GiB, and app-prod from 7.149 GiB to 2.478 GiB. Flushing deletions before trimming was necessary for that saving.

The normal Incus scenario run `a0c593d015f86f8c847a6aee444897b4` used two workers and the default limits. CPU stayed at one vCPU on every Node. Its cold flow started from the unchanged base image and completed production cloning, the first deployment, and hydration.

| Scenario | Result |
| --- | --- |
| `cold-four-node` | Passed all 15 final readiness probes and exact cleanup. |
| `cold-construction-cleanup` | Passed the injected failure and exact cleanup checks. |
| `snapshot-lifecycle` | Passed all 25 readiness probes. |
| `snapshot-isolation` | Passed all 25 readiness probes and marker isolation. |
| `snapshot-extension` | Passed all 30 readiness probes with 1 GiB for the added production Node. |

The observer recorded 367 observations, including 348 samples of guest memory. No sampled guest reported an out-of-memory event. The recorded limits were 1536 MiB, 1 GiB, and 2 GiB. Startup and cleanup can prevent a guest read, so the observations are samples rather than a continuous guarantee.

Every attempt completed exact cleanup. The final host audit found no task VMs, networks, storage volumes, or ZFS datasets. It confirmed that the shared snapshots and unowned VM retained their CPU and memory settings. Other task topologies remained present. Detailed logs, scenario results, memory observations, and check receipts are retained outside the repository.

Cold diagnostics found two production scan defects. After SSH started in the private `/home/orbit` directory, `sudo -u` changed the user without changing the working directory. GNU `find` could not restore that directory. Source classification failed with `app-prod.source_classification_failed`. Retained-release listing then failed strict receipt validation with `deployment.receipt_invalid`, including after a clone had already become active. Both scripts now enter `/` before switching users. Regression tests run the scripts with different real users, keep the SSH home private, and verify source ownership and scan failure guards. The uniform 2 GiB control had failed at the same classification step, so this failure did not support raising the memory limits.

Verification also found three harness assumptions. Snapshot clones retained the old Gateway endpoint before dependency installation. Fresh production Nodes had no TLD for clone preview routes. Both original failures reproduced on unchanged main at 2 GiB per Node. The harness repairs clone identity before hydration and assigns each fresh production Node its own name as its TLD through the existing provision command.

Native production HTTPS checks also forced the Gateway IP, although the cold recipe's Gateway is not the Router. Hydration and readiness now follow managed private DNS and continue to verify the Orbit CA certificate. Tests cover both probes, the legacy local site, and certificate failures.

### Limits

The workload and passing scenarios cover the sample topology. They do not establish memory requirements for larger applications, parallel PHP test suites inside guests, or long-running database load. The XFS tests ran after the ext4 tests on a shared host, so their timings are indicative. The space and isolation checks provide stronger evidence than a small timing difference.

The temporary XFS mount, loop device, and benchmark files were removed before the memory reboot checks. After release, the cleanup audit found no experiment VMs, network, storage volumes, or ZFS datasets. The shared snapshot VMs and other work remained untouched.
