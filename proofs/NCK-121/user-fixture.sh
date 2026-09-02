#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

mode=${1-}
user=${2-}
[[ "$user" =~ ^[a-z_][a-z0-9_-]*$ ]] || fail "invalid fixture user"

install_user() {
  local authorized=$1
  local orbit_rule
  local sudoers

  ! id -u -- "$user" >/dev/null 2>&1 || fail "$user already exists"
  sudo useradd --create-home --shell /bin/bash -- "$user"

  if [[ "$authorized" == true ]]; then
    sudo install -d -m 0700 -o "$user" -g "$user" "/home/$user/.ssh"
    sudo install -m 0600 -o "$user" -g "$user" /home/orbit/.ssh/authorized_keys "/home/$user/.ssh/authorized_keys"
    sudoers=$(mktemp)
    trap 'rm -f -- "$sudoers"' EXIT
    orbit_rule=$(sudo sed -n 's/^orbit //p' /etc/sudoers.d/orbit-e2e-orbit)
    [[ -n "$orbit_rule" ]] || fail "the approved Orbit proof privilege is missing"
    [[ "$(sudo grep -c '^orbit ' /etc/sudoers.d/orbit-e2e-orbit)" == 1 ]] \
      || fail "the approved Orbit proof privilege is ambiguous"
    printf '%s %s\n' "$user" "$orbit_rule" >"$sudoers"
    chmod 0440 "$sudoers"
    sudo visudo -cf "$sudoers" >/dev/null
    sudo install -m 0440 -o root -g root "$sudoers" "/etc/sudoers.d/$user"
    rm -f -- "$sudoers"
    trap - EXIT
    assert_non_orbit_boundary "$user"
  fi

  echo "user-fixture: installed $user authorized=$authorized on $(hostname)"
}

case "$mode" in
  install-authorized)
    install_user true
    ;;
  install-noauth)
    install_user false
    [[ ! -e "/home/$user/.ssh/authorized_keys" ]] || fail "$user unexpectedly has an authorized key"
    [[ ! -e "/etc/sudoers.d/$user" ]] || fail "$user unexpectedly has added sudo privileges"
    ;;
  remove)
    id -u -- "$user" >/dev/null 2>&1 || fail "$user is missing before fixture cleanup"
    sudo rm -f -- "/etc/sudoers.d/$user"
    sudo userdel --remove -- "$user"
    ! id -u -- "$user" >/dev/null 2>&1 || fail "$user remains after fixture cleanup"
    echo "user-fixture: removed $user from $(hostname) after evidence"
    ;;
  *)
    fail "unknown user fixture mode [$mode]"
    ;;
esac
