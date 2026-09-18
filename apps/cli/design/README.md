# Design sketches

A design sketch is a command that shows an intended CLI experience before the feature exists. It uses the CLI's own prompts, progress tree, and failure renderer, and it runs no Gateway request. A scripted scenario supplies the answers, the delays, and the outcome.

Sketches are dev-only. They load only when `ORBIT_DESIGN=1` is set, they are not part of the command surface, and the binary build excludes this directory.

```bash
ORBIT_DESIGN=1 apps/cli/orbit list | grep design:
ORBIT_DESIGN=1 apps/cli/orbit design:node-add
ORBIT_DESIGN=1 apps/cli/orbit design:node-add --outcome=package-failure --pace=0.5
```

Each sketch takes `--outcome` to select where it fails and `--pace` to set the seconds each step takes. Drive a sketch from a terminal one key at a time; Laravel Prompts reads one key per read and ignores keys that arrive together.

## Replaying recorded responses

A real command can run in a real terminal against a recorded Gateway response, so the rendering the contract tests hold can be watched live. Name the fixtures under `packages/php-sdk/fixtures` in `ORBIT_GATEWAY_FIXTURES` and point `ORBIT_HOME` at an empty replay home; the replay seeds a Gateway profile there.

```bash
export ORBIT_DESIGN=1 ORBIT_HOME=/tmp/orbit-replay
ORBIT_GATEWAY_FIXTURES=apps/app-list/default apps/cli/orbit app:list
ORBIT_GATEWAY_FIXTURES=instances/instance-create/candidate-required apps/cli/orbit instance:create 1 3 release-name
```

## Flows

A flow file under `flows/` is the agreed way through a sketch: the sketch, its arguments, the keys to send at each prompt, and the text each step must show. It is the file to look up before a demo, the script a cheaper model replays, and the record of what was agreed.

```bash
bin/cli-flow --list
bin/cli-flow node-add
bin/cli-flow node-add --keep /tmp/node-add-recording
```

`bin/cli-flow` replays the flow in a recorded terminal and prints one line per step and the final screen. To demo the same flow live, an agent opens a Solo terminal, runs the sketch, and sends each step's keys as separate inputs, reading the screen after each. The `send` tokens are `<enter>`, `<space>`, `<tab>`, `<backspace>`, `<esc>`, `<up>`, `<down>`, `<left>`, and `<right>`; text prompts accept whole strings, selection prompts need one key per step.

| Flow | Shows |
| --- | --- |
| `node-add` | Every input prompted, one invalid name, two roles, host key approval, success. |
| `node-add-package-failure` | Every input given as options, failure while installing packages. |

| Sketch | Shows |
| --- | --- |
| `design:node-add` | Prompts for every missing input, host key approval, and the provisioning steps as a progress tree. |
| `design:instance-show` | One App instance as tabs: Overview detail, Processes list, Schedules list; Tab switches, Enter selects a row. |
| `design:top` | A live top-like screen on php-tui: Nodes and Processes panes that refresh on a tick, arrow keys, `q` leaves. |

When a sketch is accepted, its scenario becomes the mock Gateway scenario for the real command, and its transcript becomes the expected output of that command's tests.
