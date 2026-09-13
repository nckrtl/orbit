# ORB-262 development

Flow: discovery

## Cause

`NativeToolInspector` treated any APT version that `DebianVersionNormalizer`
cannot reduce to SemVer as `ToolInspectionException`. Doctor mapped that to
`tool.inspection_failed`.

`tool:show` reads the stored raw `installed_version`. Unconstrained install
already accepts Debian versions such as `16+257build1.1` (`postgresql-client`)
and `2:1.24~2build1` (`golang-go`). Doctor then contradicted that healthy
intent.

ADR 0004 already returns a nullable normalized version and requires only
installed state when `version_constraint` is null. `ToolDoctorProbe` already
kept unconstrained installed Tools healthy when `normalizedVersion` is null.

## Change

Return `ToolInspectionData(true, normalizeVersion(raw))` after a successful
installed-version probe. A null normalized version is installed, not
unverifiable. Constrained Tools still produce `tool.inspection_failed` when
the version cannot be compared.

## Checks

- `apps/gateway` focused Pest: `NativeToolInspectorTest`, `ToolDoctorProbeTest`
- `apps/gateway` `composer check` passed (guidance, rector, pint, phpstan)
- Incus: not required
- Discovery development only; isolated acceptance proof not run
