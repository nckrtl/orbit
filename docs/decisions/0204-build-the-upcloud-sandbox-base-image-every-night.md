# ADR 0204: Build the UpCloud sandbox base image every night

UpCloud Project sandboxes start from one shared base template that the Gateway rebuilds from a clean VM every night. The template contains the `app-dev` toolchain, the Pi executable, ZFS, and warm Composer and npm caches for every Project on the lane. Each claim still sets up its Project from scratch.

## Status

In progress.

Principle: [Deterministic first](/mission#principles). Every sandbox starts from an image that code built from a known source on a known date. No image carries state from an earlier task.

## Context

[ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) gives each task group its own VM. The UpCloud driver clones the public Ubuntu template and installs everything at boot. Measurements from 7 to 9 Oct 2026:

- **Creation to enrolled Node took about 6 minutes** in two of three recorded runs. No run reached Pi readiness, so the time to a working group is unknown.
- **UpCloud builds the server in about 1¾ minutes.** No image change shortens this.
- **Cloud-init took 42 seconds**, mostly package updates and installs. Installing the `app-dev` packages, PHP 8.5, and Node 22 took another 82 to 88 seconds.
- **One CPU and 1 GB of memory is too small.** After one Laravel install, 291 MB was free and the VM was already swapping. Tests, Vite, and Docker services would swap heavily. `STARTER-2xCPU-2GB` costs about €0.012 per hour and includes a 30 GB disk.
- **ZFS works on the target system.** OpenZFS 2.4.1 loads on the Ubuntu 26.04 kernel with block cloning on. With the ARC capped at 128 MB, it used 82 MB. A fresh Laravel app used 172 MB of pool space. A `zfs clone` of it took 70 ms and 8 KB.
- **The disk size is not explained.** The driver requests 20 GB; the current plan includes 10 GB. No recorded measurement supports 20 GB.

About 20 Projects may use the lane. A template per Project would need 20 images built and kept current.

## Decision

The Gateway builds one UpCloud base template each night and starts every Project sandbox from the newest published one.

### Plan and disk

UpCloud sandboxes use `STARTER-2xCPU-2GB` with its included 30 GB Standard disk. A faster VM finishes sooner, so the higher hourly price costs little per group.

### One base template

All Projects share one base template. It contains:

- the cloud-init prerequisites, the bootstrap and `app-dev` packages, PHP, Node, and Caddy;
- `zfsutils-linux`, with `zfs_arc_max` set to 128 MB;
- the pinned Pi executable;
- Composer and npm caches, warmed as described below;
- the managed `orbit` account with no authorized keys.

It contains no Project source, credentials, fleet identity, WireGuard keys, SSH host keys, `machine-id`, or ZFS pool.

### Disk layout

The template is built on a 20 GB disk with root filling it. A claim clones the template into the plan's 30 GB disk. Cloud-init does not grow root. On first boot it creates a partition in the remaining 10 GB, creates the ZFS pool there, and mounts a dataset at the checkout path `/home/orbit/orbit`, owned by `orbit` with mode `0700`. Root keeps 20 GB for the system, swap, caches, and Docker images.

Creating the pool on first boot gives every VM its own pool identity. No pool needs to be exported before templating.

### Nightly build

A Gateway schedule runs one build each night:

1. Reserve a build record with a unique identity before any provider call. Create the build VM from the pinned public Ubuntu template, with an `orbit-image-build` ownership label and a firewall that admits SSH only from the Gateway. Creation follows the driver's at-most-once and recovery rules.
2. Run the versioned setup script from `resources/compute`.
3. Warm the caches. The Gateway reads `composer.lock` and `package-lock.json` from each Project's default branch with GitHub App read access, and sends only those files to the VM. The VM runs `composer install --no-scripts --no-plugins` and `npm ci --ignore-scripts` in temporary directories, then deletes those directories. No Project code runs during the build.
4. Audit and clean the VM: remove SSH host keys, `machine-id`, the build key, shell history, and any credentials. Run `cloud-init clean`. Stop the VM.
5. Templatize its disk. Delete the build server. Record the template UUID, build date, and setup script checksum.
6. Smoke-test the template: create one VM from it, confirm cloud-init finishes, the pool mounts, PHP and Pi start, then destroy it.
7. Publish the template only after the smoke test passes. New reservations pin the newest published template. Existing reservations keep their recorded image.

A failed step keeps the previous published template. The build VM and smoke VM are always destroyed, and a failure raises an alert.

### Template retention

Orbit keeps the newest published template and the one before it, for rollback. It deletes an older template once no reservation that is not destroyed records it. A template is never updated in place; each build publishes a new UUID.

### Driver changes

- `SandboxSpec` accepts the published base template. It no longer requires the public Ubuntu template.
- The size `starter-small` is replaced by a size that maps to `STARTER-2xCPU-2GB`.
- `UpCloudCloudInit` creates the user and authorizes the Gateway key, disables root growth, and prepares the pool. It no longer updates or installs packages.

## Rejected alternatives

- **A template per Project.** Faster claims, but about 20 images to build, store, and keep current.
- **Recycle a finished sandbox into the next template.** It would keep caches fresh with no extra build, but an agent and Project install scripts ran on that disk. Removing known files cannot prove the disk is clean, and any change would spread to every sandbox that starts from that template, for every Project. This breaks the isolation of ADR 0200.
- **Weekly builds.** A nightly build costs about one cent in VM time and keeps security updates and caches at most a day old.
- **Keep installing everything at boot.** Every claim and every recovery after review feedback pays the full install time and depends on package mirrors being available.
- **A ZFS pool inside the template.** Every clone would share one pool identity, and the pool would need to be exported before templating. A pool created on first boot avoids both.

## Consequences

- Claims and recoveries skip package installation. Dependency installs are faster through the warm caches.
- The template costs about €0.33 per GB per month, about €6.60 for one 20 GB template and €13.20 while two are kept. Build and smoke VMs cost about one cent a night.
- Cached packages from every Project's lock files are present on every sandbox. Paid packages that need authentication are left out of the warm cache.
- The template exists only in the build zone, `nl-ams1`. Another zone needs its own build.
- The nightly build depends on GitHub and package mirrors. A failed night leaves the previous template in use.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm), whose Images section this decision details for the UpCloud lane
- Detail: [Compute drivers](/reference/compute-drivers): plan, disk layout, cloud-init, base template build, and retention.
- Verify: Live UpCloud runs check these outcomes.
  - A nightly build publishes a template, and its smoke VM passes.
  - A claim from the published template reaches Pi readiness, with phase timings recorded.
  - A failed build keeps the previous template and leaves no build VM behind.
