# Rust agent

`orbit-agent` is an outbound-only Linux service. Keep credentials and signatures out of logs, never add command execution or listeners, and load trust only from `/etc/orbit/agent/ca.pem`. Keep process-name filtering, event serialization, and snapshot sizing covered by unit tests.

## Checks

From this directory, run:

```sh
cargo fmt --all -- --check
cargo clippy --all-targets -- -D warnings
cargo test --locked
cargo build --release --locked
```

CI additionally builds the `x86_64-unknown-linux-musl` and `aarch64-unknown-linux-musl` release targets. Commit `Cargo.lock` with dependency changes.
