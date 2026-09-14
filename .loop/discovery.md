# Discovery observations

Issue: ORB-345
Flow: discovery
Candidate: `f98f3828d6993a689146fb0120ccdf4b9ff9896c`
Attempt: `b0d26d889d96e03809a953b7716fceeb`

Discovery development only; isolated acceptance proof not run.

## Live handoff rehearsal

On the disposable `app-dev` Node, Herdr 0.8.2/protocol 20 ran in
`orb345-herdr-rehearsal.service` with two live panes:

- `w1:p1`, terminal `term_65b73293892981`, process 5968
- `w1:p2`, terminal `term_65b732a056c082`, process 6094

A runtime systemd drop-in changed supervision from `ExitType=main` and
`KillMode=control-group` to `ExitType=cgroup` and `KillMode=process` without
changing the server process or its start timestamp. Herdr's supported live
handoff then replaced the server with 0.9.0/protocol 22.

Both pane processes, workspace identity, and pane identities survived. Herdr
intentionally assigned new terminal identities during import:

- `w1:p1` -> `term_65b7330809a911`
- `w1:p2` -> `term_65b7330809b332`

The replacement server process was 7036. The unit remained active with
`MainPID=0`, which is the expected systemd state for `ExitType=cgroup` after
the original main process exits while imported pane processes remain in the
unit cgroup.

## External adoption and observer publication

The Gateway adopted `orb345-rehearsal` on Node `app-dev` as an externally
managed Herdr session. The retained record had:

- `management=external`
- `process_id=null`
- `herdr_version=0.9.0`
- `protocol=22`
- `observer_status=published`
- `observer_url=wss://orb345-rehearsal.herdr.app-dev.beast`
- active session state with no failed step or error code

The observer adapter was active as
`orbit-herdr-observer-orb345-rehearsal.service`, bound only to
`127.0.0.1:7411`. Private DNS resolved the observer hostname on `app-dev`, and
an HTTPS request reached `10.44.0.2` with successful certificate verification.
The receive-only endpoint rejected the unauthenticated request with HTTP 403,
as expected.

Orbit issued a 60-second grant with scope `terminal.observe`, bound to pane
`w1:p1`, expected terminal `term_65b7330809a911`, viewport 120x40, and origin
`https://commander.discovery.test`. The bearer value was not retained; its
SHA-256 digest was
`07c5d4c61355227f62a12ad677e0ca9686ec241ddf9488b158d9ba867201961b`.

## Discovery infrastructure note

The retained snapshot configured `ORBIT_GATEWAY_CHECKOUT` as
`/home/orbit/orbit-gateway`, while the synchronized Gateway checkout was
actually `/home/orbit/orbit/apps/gateway`. The generated private-DNS unit first
failed at systemd's `CHDIR` step and then could not find `artisan`. A disposable
symlink from the configured path to the synchronized Gateway checkout repaired
the discovery image. Re-running DNS convergence and the identical adoption
published the observer successfully. This path mismatch predates and is
outside the ORB-345 product candidate; it is recorded here so the discovery
result is not mistaken for an unmodified snapshot proof.

