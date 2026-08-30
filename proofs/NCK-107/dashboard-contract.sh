#!/usr/bin/env bash
# Grafana serves the Orbit Node Resources dashboard with its eight panels over https://metrics.orbit.
source /var/lib/orbit-e2e/proof/lib.sh

password=$(grafana_password)
served=$(wait_for_dashboard "$password")

printf '%s' "$served" | php /var/lib/orbit-e2e/proof/dashboard-contract.php "$(scraped_nodes)"
