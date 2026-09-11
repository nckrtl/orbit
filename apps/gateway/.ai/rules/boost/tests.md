---
paths:
  - 'tests/**'
---

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run Pest through `composer test` or `composer test:affected`; TIA selects the affected tests.
- Rerun a test after each change to it.
- Do not pass a test path, filter, group, or suite. Pest disables TIA for partial runs even when `--tia` is present.
- After the TIA tests pass, run `composer check` locally and hand off to an independent reviewer who runs root `composer check` across all projects with TIA.
