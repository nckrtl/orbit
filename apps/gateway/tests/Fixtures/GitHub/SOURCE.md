# GitHub review fixture source

Captured with read-only `gh api --method GET` calls on 2026-10-02:

- `repos/nckrtl/orbit/pulls/111/reviews` (7 records)
- `repos/nckrtl/orbit/pulls/111/reviews/5095680829`
- `repos/nckrtl/orbit/pulls/111/reviews/5095680829/comments?per_page=100&page=1` (15 records)

`review.json` and `comment.json` project the fields used by retrieval from that review and its first comment. Repository, account, IDs, prose, path, and diff content are sanitized. Field types, actual submission/creation/update timestamps, commit IDs, state, and current/original positions are retained. The real selected-review comment endpoint omitted line/side fields; tests also exercise their documented optional modern forms. Boundary and malformed variants are deliberate mutations of these shapes, not additional captured records.

## Canonical pagination headers

Pagination captures use read-only `gh api --include --method GET` calls with `X-GitHub-Api-Version: 2022-11-28`, also on 2026-10-02:

- `repos/nckrtl/orbit` independently confirms repository ID `1348221080` and `full_name`.
- `repos/nckrtl/orbit/pulls/111/reviews?per_page=1&page=1` and `/reviews/5095680829/comments?per_page=1&page=1` return canonical `/repositories/1348221080/pulls/111/...` next/last links.
- Review pages 1–7 with `per_page=1`, and selected-review comment pages 1–5 with `per_page=3`, supply the complete Link-header sequences in `pagination.json`. The smaller page sizes obtain real pagination from this small historical PR without creating or editing any GitHub record.

`repository.json` projects the independently read repository ID/name. Repository ID `1348221080` becomes `555001`, PR `111` becomes `7`, and review `5095680829` becomes `101` in these fixtures. The Link-header paths, relation syntax, query order, and page sequence otherwise remain captured values. Tests normalize only `per_page` to the production value `100` and use unique sanitized review/comment records to exercise every captured page. Wrong-repository and other hostile variants are deliberate mutations.

No token or unrelated response header is included. Raw captures are uncommitted under `.orbit-artifacts/github-review/`.
