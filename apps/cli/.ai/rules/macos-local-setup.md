---
paths:
  - 'app/Commands/Nodes/SetupNodeCommand.php'
  - 'app/Data/NodeSetup*.php'
  - 'app/Exceptions/LocalNodeSetupException.php'
  - 'app/Services/NodeSetup/**'
  - 'app/Support/LocalDiagnosticRedactor.php'
---

# macOS Local Setup

- `node:setup app-dev` accepts no target identity or confirmation bypass.
- Require a readable and writable controlling `/dev/tty` before the first HTTP request.
- Use an exclusive mode-`0600` script and FIFO below the verified operating-system temporary directory.
- Execute only the fixed `/usr/bin/script` and `/bin/bash` argv contract.
- Bound and redact diagnostics before the result request.
- Validate ownership and process identity before signaling or cleanup.
- Never retain setup state or expose scripts, diagnostics, paths, process IDs, or raw exceptions.
- Never add hidden commands, an Agent, a generic executor, remote SSH, or remote sudo.
