# Web build artifact fixture source

Captured with read-only `gh api --method GET` calls against the public `nckrtl/orbit` repository on 2026-10-07:

- `artifacts.json`: `repos/nckrtl/orbit/actions/artifacts?name=sandbox-tia-0-a30e23427e69…&per_page=100`. CI uploads that artifact on a push to `main` with `actions/upload-artifact`, the same way it uploads `web-dist-<sha>`. No `web-dist-*` artifact existed yet.
- `artifacts-none.json`: the same listing for `name=web-dist-a30e23427e69…`, which matched nothing.
- `run.json`: `repos/nckrtl/orbit/actions/runs/37639888555`, the run that uploaded the artifact. It is projected to `id`, `name`, `event`, `status`, `conclusion`, `head_branch`, `head_sha`, `path`, `run_attempt`, and the `id` and `full_name` of `repository` and `head_repository`. It was still `in_progress`: the artifact is listed while its run continues.
- `repos/nckrtl/orbit/actions/artifacts/11492530096/zip` answered `302` with a `Location` on `productionresultssa9.blob.core.windows.net`. The signed query is not kept. The archive's SHA-256 equals the listing's `digest`.

Tests rename the artifact to `web-dist-<sha>`, replace its `digest` and size with those of a generated archive, and change single fields (`expired`, `event`, `head_repository`) to build hostile variants. Those are deliberate mutations of the captured shapes, not more captured records. The listings hold no token, and no response header is kept. Raw captures stay uncommitted under `.orbit-artifacts/web-artifact/`.
