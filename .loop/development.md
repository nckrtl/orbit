# ORB-259 development record

Flow: discovery. Candidate: see published artifact binding.

## Acceptance evidence

1. Matrix: `apps/gateway/tests/Feature/Domain/RouterLanIngressPolicyTest.php`
2. Ownership / no broadening: catalog test in that file; `NativeNodeRoleFirewallManagerTest` Router vs AppProd converge
3. Lifecycle expand-before / prune-after: `RouterLanIngressReconciliationTest`, `RemoveNodeTest`, Router converge/remove
4. Failure recovery: expand-failure and prune-failure cases in those files; manager restore on apply failure
5. `apps/gateway` `composer check` passed on `e54339e7`. Focused LAN tests: 38 passed. Gateway `composer test:affected` selected 28 files; 466 passed. Four `ApplicationStateInspectorsTest` Git-ancestry cases failed in this environment (third tuple bit 0); they are unrelated to LAN ingress.

Incus topology was not available. DNS address selection was not changed.

Discovery development only; isolated acceptance proof not run.
