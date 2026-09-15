# ORB-351 proof continuation

Flow: proof. Incus is required. The promoted snapshot is restored and ORB-364 fixed the Ubuntu hibernator install failure. Its snapshot closeout runs before this fresh proof. ORB-351 discovery remains allocated; its prior failed proof is retained for diagnosis and must be released before proving the updated candidate. No shared topology substitutes for this issue's proof.

## Candidate and fixtures

Author in the local ORB-351 worktree. Commit and push the complete source, run
the exact-head Builder gate on Beast, and publish candidate-bound artifacts. Fast-forward
the clean Beast ORB-351 branch, fetch artifacts with the exact candidate and
artifact SHAs, and restore `.loop/` from that validated artifact commit. Do not
publish one candidate twice with different artifacts.

The portable registry fixture checks all 106 public and 20 framework surfaces,
signatures, aliases, enabled Herdr visibility, and recorded source hashes. The
harness must establish that the guest checkout is the submitted candidate.

Synchronize the existing discovery through the supported harness and exercise the tools inside app-dev through Solo as needed for changed behavior. Run the full proof on a
separate fresh topology using `.loop/proof/ORB-351.json`, then capture immutable
acceptance before later Solo review actions. Python needs the venv module in the
guest; a missing runtime dependency is a setup failure, not passing evidence.
The setup records pip's installation report with dependency download hashes.

## Complete export

The `full-artifact-manifest` action retains the complete recordings, file-size
and SHA-256 manifest, and a compressed archive beside the private acceptance
directory. It prints the archive path, size and SHA-256. Before release, export
it through recorded `exec --proof --review-action=...` reads. Each read invokes:

```text
python3 /var/lib/orbit-e2e/proof/orb-351-export.py ARCHIVE_PATH ARCHIVE_SHA256 OFFSET
```

Offsets start at zero and advance by the returned byte count, at most 2048
bytes per call. Decode `base64`, require consecutive offsets and the same total,
then verify the complete archive size and SHA-256. Extract only safe relative
regular-file members into a private directory and verify every manifest file's
size and hash. Retain the archive, manifest and full recordings in durable
private evidence. A stdout tail or archive pointer alone is not export.

Solo review must separately record keyboard interaction and inspect dimensions,
Unicode, dim styling, visible animation and terminal restoration. Record review
results through the current harness; do not append them to acceptance artifacts.
Keep the successful topology for independent review and authorized closeout.

## Check environment

The initial macOS Builder attempt at 57b4b742 failed: Gateway cold TIA exhausted
the default PHP 128 MB memory limit, and E2E fixtures require Linux `/proc`,
GNU tar and current Bash behavior. CLI, Docs and SDK checks passed. Do not
modify the harness to make this foundation branch portable. Use the already
bootstrapped Beast worktree for the full candidate gate; its PHP memory limit
is unlimited. A local temporary PHP ini can raise the memory limit for isolated
local diagnostics, but it cannot replace the Linux harness environment.

## Closeout invocation

Invoke the closeout command from the clean primary main checkout on Beast. The issue is resolved through the registered worktree; snapshot refresh uses the invoking checkout as its source. Running the feature checkout entry point fails the clean-main identity check before refresh. Preserve retained proof and retry from primary main after verifying the exact merge.
