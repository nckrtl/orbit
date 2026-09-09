# Loop improvement verification

Issue: TST-501 (isolated harness smoke identity for the authorized loop improvement)
Review verdict: verification in progress

Verify candidate-bound artifact publication and fixture staging on gateway, app-dev, and app-prod. Assert the guest product checkout has no .loop paths and equals the published candidate. Capture and release proof resources; require equivalence to remain exact with no active proof lease. Automated tests cover immutable refs, index preservation, incomplete evidence refusal, and cleanup retry. Full suites run in CI. This plan intentionally leaves PHP observation disabled: it checks artifact placement and retained evidence rather than PHP input non-overlap.
