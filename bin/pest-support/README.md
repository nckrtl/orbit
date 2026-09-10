# Pest monorepo support

Composer runs `bin/pest-setup` before generating each project's autoloader. It applies the runtime changes from the existing local Pest fork to the locked upstream distribution. Every input and output file is checked with SHA-256. Repeated installs verify the patched files and make no change. An unsupported version or modified input fails setup.

The base is Pest v5.1.3, commit `20a5aacaca4fce9116922f42d2942b3f9f5bbfc6`. The source fork is commit `156e40896aa8cab8795eae9d0d3f751771a856fa`. It combines [Pest PR #1809](https://github.com/pestphp/pest/pull/1809), [Pest PR #1834](https://github.com/pestphp/pest/pull/1834), and the local parallel-worker consumer-autoloader fix `bd4136c7`. The patch contains only runtime files; the fork's Composer version declaration and its tests are excluded. Pest's MIT license is retained beside the patch.

The fixes resolve changes relative to the Composer project inside a Git monorepo, keep each project's baseline separate, including linked worktrees, and load the consuming project's autoloader in linked and parallel runs. Full CI runs use this same runner with `--no-tia`.

To upgrade Pest, regenerate the patch and file hashes from reviewed commits, exercise monorepo selection and parallel execution in a fresh worktree, and run full CI. Remove this bundle and the Composer hooks when the locked upstream release includes these fixes. Do not edit an installed vendor directory by hand.
