#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

password=$(grafana_password)
address=$(wireguard_address)
search=$(curl --silent --show-error --fail --max-time 15 --user "admin:$password" \
  "http://$address:3000/api/search?query=Orbit%20Node%20Resources")
folder=$(echo "$search" | php -r '
  foreach (json_decode(stream_get_contents(STDIN), true) as $row) {
    if (($row["title"] ?? null) === "Orbit Node Resources") {
      echo ($row["folderTitle"] ?? ""), "|", ($row["folderUid"] ?? "");
      exit(0);
    }
  }
  exit(1);
') || fail "Orbit Node Resources dashboard is absent"
[[ "$folder" == 'Orbit|orbit' ]] || fail "dashboard folder is [$folder], expected Orbit|orbit"

echo "dashboard-folder: Orbit Node Resources is in Orbit folder uid orbit"
