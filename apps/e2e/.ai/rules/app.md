# Application rules

Keep the E2E application console-only. Keep commands thin and move reusable
logic into small, typed services. Never add HTTP, database, queue, or web
features. Validate all external command results and redact sensitive output.
