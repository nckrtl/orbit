#!/usr/bin/env bash
# A leaf that has not expired yet but falls inside the thirty day renewal margin is replaced before
# it lapses. Convergence is the only renewal opportunity there is, so waiting for actual expiry
# would mean a guaranteed outage.
source /var/lib/orbit-e2e/proof/lib.sh

bash /var/lib/orbit-e2e/proof/plant-leaf.sh near-expiry >/dev/null
before_pem=$(served_pem)
before=$(pem_serial "$before_pem")
pem_is_expired "$before_pem" && fail "the planted near-expiry leaf should still be valid"
# It is still trusted, so HTTPS works before renewal too; imminent expiry is the only thing wrong.
status=$(https_status)
[[ "$status" == 302 ]] || fail "the near-expiry leaf should still serve traffic, got [$status]"

converge_metrics

after_pem=$(served_pem)
after=$(pem_serial "$after_pem")
[[ "$after" != "$before" ]] || fail "the near-expiry leaf [$before] was not renewed"
remaining=$(pem_remaining_days "$after_pem")
(( remaining > 300 )) || fail "the replacement expires in $remaining days, wanted a full lifetime"

echo "near-expiry-leaf-reissued: $before -> $after, now $remaining days of life"
