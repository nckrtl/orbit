# Design sketches

A design sketch is a command that shows an intended CLI experience before the feature exists. It uses the CLI's own prompts, progress tree, and failure renderer, and it runs no Gateway request. A scripted scenario supplies the answers, the delays, and the outcome.

Sketches are dev-only. They load only when `ORBIT_DESIGN=1` is set, they are not part of the command surface, and the binary build excludes this directory.

```bash
ORBIT_DESIGN=1 apps/cli/orbit list | grep design:
ORBIT_DESIGN=1 apps/cli/orbit design:node-add
ORBIT_DESIGN=1 apps/cli/orbit design:node-add --outcome=package-failure --pace=0.5
```

Each sketch takes `--outcome` to select where it fails and `--pace` to set the seconds each step takes. Drive a sketch from a terminal one key at a time; Laravel Prompts reads one key per read and ignores keys that arrive together.

| Sketch | Shows |
| --- | --- |
| `design:node-add` | Prompts for every missing input, host key approval, and the provisioning steps as a progress tree. |

When a sketch is accepted, its scenario becomes the mock Gateway scenario for the real command, and its transcript becomes the expected output of that command's tests.
