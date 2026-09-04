#!/usr/bin/env bash
set -euo pipefail

checkout=/home/orbit/orbit

test -f "$checkout/.loop/plan.md"
test -f "$checkout/.loop/proof/ORB-121.json"
test -x "$checkout/.loop/proof/prove-loop-workspace.sh"
test ! -e "$checkout/.orbit/plan.md"
test ! -d "$checkout/proofs"
test -z "$(git -C "$checkout" ls-files proofs)"
! git -C "$checkout" check-ignore -q .loop/plan.md
git -C "$checkout" ls-files --error-unmatch \
  .loop/plan.md \
  .loop/proof/ORB-121.json \
  .loop/proof/prove-loop-workspace.sh >/dev/null

cd "$checkout/apps/e2e"
./vendor/bin/pest tests/Feature/Configuration/AgentRoleContractTest.php \
  --filter='binds the external merge closeout lifecycle'
