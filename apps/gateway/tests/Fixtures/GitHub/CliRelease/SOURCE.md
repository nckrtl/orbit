# CLI release fixtures

These files have the shape of a published Orbit CLI release on GitHub, as `.github/workflows/orbit-cli-release.yml`
publishes it: a lightweight tag `cli-v0.N.0` on the commit, a release with one binary per platform and `SHA256SUMS`.

- `release.json` is the `GET /repos/nckrtl/orbit/releases/tags/agent-v0.3.0` response captured on 7 Oct 2026,
  with the tag, names, IDs, sizes, dates, and URLs rewritten to `cli-v0.4681.0`.
- `tag-ref.json` is a `GET /repos/{owner}/{repo}/git/ref/tags/{tag}` response for a lightweight tag.
- `orbit-0.4681.0-*` are stand-in binaries. `SHA256SUMS` was written from them as `bin/orbit-cli-release-assets`
  does: `LC_ALL=C sha256sum -- orbit-0.4681.0-* | LC_ALL=C sort -k 2`.

`apps/cli/tests/Fixtures/SelfUpdate` holds copies of the binaries and `SHA256SUMS`, so the CLI tests download the
same bytes that the recorded Gateway response names.
