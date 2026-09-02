#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

mode=${1-}

set_all_users() {
  local user=$1

  php -r '
    $pdo = new PDO("sqlite:".$argv[1]);
    $statement = $pdo->prepare("update nodes set user = :user");
    $statement->execute(["user" => $argv[2]]);
    if ($statement->rowCount() !== 3) {
      fwrite(STDERR, "expected three node users to change\n");
      exit(1);
    }
  ' -- "$NCK121_DB" "$user"

  echo "database-fixture: all node identities set to [$user]"
}

snapshot_and_corrupt() {
  local label=$1
  local user=$2
  local snapshot="/tmp/nck121-$label.sqlite"

  [[ "$label" =~ ^[a-z]+$ ]] || fail "invalid failure label"
  [[ ! -e "$snapshot" ]] || fail "snapshot already exists for $label"

  sudo systemctl stop php8.5-fpm
  trap 'sudo systemctl start php8.5-fpm' EXIT
  php -r '$pdo = new PDO("sqlite:".$argv[1]); $pdo->exec("pragma wal_checkpoint(truncate)");' -- "$NCK121_DB"
  cp --preserve=mode,timestamps -- "$NCK121_DB" "$snapshot"
  php -r '
    $pdo = new PDO("sqlite:".$argv[1]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();
    $id = $pdo->query(
      "select nodes.id from nodes join node_roles on node_roles.node_id = nodes.id where node_roles.role = \"metrics\" and node_roles.status = \"active\""
    )->fetchColumn();
    if (! is_int($id) && ! ctype_digit((string) $id)) {
      throw new RuntimeException("active Metrics node is missing");
    }
    $statement = $pdo->prepare("update nodes set user = :user where id = :id");
    $statement->execute(["user" => $argv[2], "id" => (int) $id]);
    if ($statement->rowCount() !== 1) {
      throw new RuntimeException("active Metrics identity was not changed");
    }
    $pdo->commit();
  ' -- "$NCK121_DB" "$user"
  sudo systemctl start php8.5-fpm
  trap - EXIT

  echo "database-fixture: snapshotted $label and corrupted active Metrics identity to [$user]"
}

restore_snapshot() {
  local label=$1
  local snapshot="/tmp/nck121-$label.sqlite"
  local candidate="${NCK121_DB}.nck121-candidate"

  [[ "$label" =~ ^[a-z]+$ ]] || fail "invalid failure label"
  [[ -f "$snapshot" ]] || fail "snapshot is missing for $label"

  sudo systemctl stop php8.5-fpm
  trap 'sudo systemctl start php8.5-fpm' EXIT
  rm -f -- "${NCK121_DB}-wal" "${NCK121_DB}-shm" "$candidate"
  cp --preserve=mode,timestamps -- "$snapshot" "$candidate"
  mv -f -- "$candidate" "$NCK121_DB"
  cmp -s -- "$snapshot" "$NCK121_DB" || fail "database snapshot restore differs for $label"
  sudo systemctl start php8.5-fpm
  trap - EXIT
  rm -f -- "$snapshot"

  echo "database-fixture: restored exact $label database snapshot after evidence"
}

case "$mode" in
  set-all)
    [[ $# -eq 2 ]] || fail "set-all requires one user"
    set_all_users "$2"
    ;;
  snapshot-corrupt)
    [[ $# -eq 3 ]] || fail "snapshot-corrupt requires label and user"
    snapshot_and_corrupt "$2" "$3"
    ;;
  restore)
    [[ $# -eq 2 ]] || fail "restore requires one label"
    restore_snapshot "$2"
    ;;
  *)
    fail "unknown database fixture mode [$mode]"
    ;;
esac
