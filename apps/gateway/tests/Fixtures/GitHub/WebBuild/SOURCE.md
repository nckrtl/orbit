# Web build artifact fixture source

Captured with read-only `gh api --method GET` calls against the public `nckrtl/orbit` repository on 2026-10-07, from the first CI run on `main` that published a web build (run `37647307459`, commit `3c83e74ce6f2…`):

- `artifacts.json`: `repos/nckrtl/orbit/actions/artifacts?name=web-dist-3c83e74ce6f2…&per_page=100`.
- `artifacts-none.json`: the same listing for a name that matched nothing.
- `run.json`: `repos/nckrtl/orbit/actions/runs/37647307459`, projected to `id`, `name`, `event`, `status`, `conclusion`, `head_branch`, `head_sha`, `path`, `run_attempt`, and the `id` and `full_name` of `repository` and `head_repository`. The run was still `in_progress`: the artifact is listed as soon as the Web job uploaded it.
- `repos/nckrtl/orbit/actions/artifacts/11494149862/zip` answered `302` with a `Location` on `productionresultssa10.blob.core.windows.net`. The signed query is not kept. The archive's SHA-256 equals the listing's `digest`. It holds 20 regular files, Unix mode `0100644`, and no directory entries; the generated test archives use the same layout.

Tests replace the artifact's `digest` and size with those of a generated archive, rename it and its `head_sha` for commits of a test repository, and change single fields (`expired`, `event`, `head_repository`, `workflow_run.head_sha`) to build hostile variants. Those are deliberate mutations of the captured shapes, not more captured records. The listings hold no token, and no response header is kept. Raw captures, including the real archive, stay uncommitted under `.orbit-artifacts/web-artifact/`.
