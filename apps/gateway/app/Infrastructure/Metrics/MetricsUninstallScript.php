<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

/**
 * The node-local escape from a Metrics footprint with no reachable Gateway.
 *
 * Every Metrics route authorizes against the one active Gateway, and that is
 * deliberate. When the Gateway is gone the resources stay on the node with no
 * way to reach them, so Orbit leaves the operator a tool instead of a
 * procedure: this script is published on every node Orbit converges the
 * exporter onto, and it is rendered from {@see MetricsFootprint}, the same
 * constants the remote executors mutate. A documented command sequence would
 * drift away from those constants the moment either side changed; a rendered
 * script cannot, because a change to a path or a label re-renders it on the
 * next convergence.
 *
 * The script proves ownership exactly the way the remote path does: the
 * `com.orbit.managed=metrics` container and volume labels, the
 * `/etc/orbit/metrics/.orbit-owner` marker, the drop-in's `Managed by Orbit`
 * first line, and the Orbit-namespaced UFW rule comments. Anything without
 * one of those proofs is reported, never removed.
 *
 * The exporter package is deliberately left installed. Orbit installs it but
 * cannot prove it owns it — apt keeps no owner marker and a foreign scrape
 * may depend on it — so the script reports it and the exact purge command,
 * which is also what the remote removal path does.
 */
final readonly class MetricsUninstallScript
{
    public function render(): string
    {
        return strtr($this->template(), [
            '@@LABEL_KEY@@' => MetricsFootprint::ManagedLabel,
            '@@LABEL_VALUE@@' => MetricsFootprint::ManagedValue,
            '@@CONFIG_DIR@@' => MetricsFootprint::ConfigurationDirectory,
            '@@MARKER_PATH@@' => MetricsFootprint::OwnershipMarker,
            '@@MARKER_CONTENTS@@' => trim(MetricsFootprint::OwnershipMarkerContents),
            '@@CANDIDATE_SUFFIX@@' => MetricsFootprint::CandidateSuffix,
            '@@CONFIG_PATHS@@' => $this->list(MetricsFootprint::ConfigurationPaths),
            '@@CONFIG_DIRS_REVERSE@@' => $this->list(array_reverse(MetricsFootprint::ConfigurationDirectories)),
            '@@DROPIN@@' => MetricsFootprint::ExporterDropIn,
            '@@DROPIN_DIR@@' => MetricsFootprint::ExporterDropInDirectory,
            '@@DROPIN_MARKER@@' => MetricsFootprint::ExporterDropInMarker,
            '@@SERVICE@@' => MetricsFootprint::ExporterService,
            '@@PACKAGE@@' => MetricsFootprint::ExporterPackage,
            '@@EXPORTER_COMMENT@@' => MetricsFootprint::ExporterFirewallComment,
            '@@GRAFANA_COMMENT@@' => MetricsFootprint::PublicationFirewallComment,
            '@@EXPORTER_PORT@@' => MetricsFootprint::ExporterPort,
            '@@GRAFANA_PORT@@' => MetricsFootprint::PublicationPort,
            '@@INTERFACE@@' => MetricsFootprint::WireGuardInterface,
            '@@SELF@@' => MetricsFootprint::UninstallScript,
        ]);
    }

    /** @param list<string> $values */
    private function list(array $values): string
    {
        return implode("\n", array_map(static fn (string $value): string => "    '{$value}'", $values));
    }

    private function template(): string
    {
        return <<<'BASH_WRAP'
            #!/bin/bash
            @@DROPIN_MARKER@@
            #
            # orbit-metrics-uninstall — remove this node's Orbit Metrics footprint
            # without a Gateway.
            #
            # Orbit writes this script while it converges the Metrics exporter, so it
            # always matches the code that put the state here. It removes only what Orbit
            # can prove it owns and reports everything else instead of guessing.
            #
            # Re-enabling is ordinary registration. Once a Gateway is reachable,
            # take the stale role off and add it again:
            #
            #   orbit metrics:disable --force
            #   orbit metrics:enable <node>
            #
            # Usage: sudo @@SELF@@ [--force] [--dry-run]
            #
            # Exit codes: 0 removed everything Orbit owns, 2 usage or cancelled,
            # 3 something was refused or survived removal, 4 must run as root.

            set -uo pipefail

            readonly LABEL_KEY='@@LABEL_KEY@@'
            readonly LABEL_VALUE='@@LABEL_VALUE@@'
            readonly LABEL_FILTER='@@LABEL_KEY@@=@@LABEL_VALUE@@'
            readonly CONFIG_DIR='@@CONFIG_DIR@@'
            readonly MARKER_PATH='@@MARKER_PATH@@'
            readonly MARKER_CONTENTS='@@MARKER_CONTENTS@@'
            readonly CANDIDATE_SUFFIX='@@CANDIDATE_SUFFIX@@'
            readonly DROPIN='@@DROPIN@@'
            readonly DROPIN_DIR='@@DROPIN_DIR@@'
            readonly DROPIN_MARKER='@@DROPIN_MARKER@@'
            readonly SERVICE='@@SERVICE@@'
            readonly PACKAGE='@@PACKAGE@@'
            readonly SELF='@@SELF@@'
            readonly EXPORTER_COMMENT='@@EXPORTER_COMMENT@@'
            readonly GRAFANA_COMMENT='@@GRAFANA_COMMENT@@'
            readonly EXPORTER_PORT='@@EXPORTER_PORT@@'
            readonly GRAFANA_PORT='@@GRAFANA_PORT@@'
            readonly INTERFACE='@@INTERFACE@@'

            readonly CONFIG_PATHS=(
            @@CONFIG_PATHS@@
            )

            readonly CONFIG_DIRS_REVERSE=(
            @@CONFIG_DIRS_REVERSE@@
            )

            removed=()
            refused=()
            kept=()
            planned=()
            force=0
            dry_run=0
            scope='none'

            # Captured once, then every firewall decision reads these. Asking
            # ufw again between the plan and the act is how a confirm-then-act
            # tool ends up removing something the operator never saw.
            firewall_status=''
            firewall_state='unknown'
            exporter_rule_state='none'
            grafana_rule_state='none'
            exporter_rule_number=''
            grafana_rule_number=''
            node_address=''
            downgrades=()

            usage() {
                cat <<USAGE
            Usage: sudo ${SELF} [--force] [--dry-run]

            Removes this node's Orbit Metrics footprint without a Gateway.

              --force     do not ask for confirmation
              --dry-run   report what would be removed and change nothing
              --help      show this message
            USAGE
            }

            note_removed() { removed+=("$1"); }
            note_refused() { refused+=("$1"); }
            note_kept() { kept+=("$1"); }
            note_downgrade() { downgrades+=("$1"); }

            have() { command -v "$1" >/dev/null 2>&1; }

            heading() { printf '\n%s\n' "$1"; }

            report_list() {
                local title="$1"
                shift

                if [ "$#" -eq 0 ]; then
                    return
                fi

                heading "${title}"
                printf '  - %s\n' "$@"
            }

            # owned | foreign | absent
            dropin_state() {
                if [ ! -e "${DROPIN}" ]; then
                    printf 'absent\n'
                    return
                fi

                if [ "$(head -n 1 -- "${DROPIN}" 2>/dev/null)" = "${DROPIN_MARKER}" ]; then
                    printf 'owned\n'
                    return
                fi

                printf 'foreign\n'
            }

            # owned | foreign | absent
            configuration_state() {
                if [ ! -d "${CONFIG_DIR}" ]; then
                    printf 'absent\n'
                    return
                fi

                if [ "$(cat -- "${MARKER_PATH}" 2>/dev/null)" = "${MARKER_CONTENTS}" ]; then
                    printf 'owned\n'
                    return
                fi

                printf 'foreign\n'
            }

            docker_available() {
                have docker && docker info >/dev/null 2>&1
            }

            orbit_containers() {
                docker container ls --all --filter "label=${LABEL_FILTER}" --format '{{.Names}}' 2>/dev/null
            }

            orbit_volumes() {
                docker volume ls --filter "label=${LABEL_FILTER}" --format '{{.Name}}' 2>/dev/null
            }

            container_owned() {
                [ "$(docker container inspect --format "{{index .Config.Labels \"${LABEL_KEY}\"}}" -- "$1" 2>/dev/null)" \
                    = "${LABEL_VALUE}" ]
            }

            volume_owned() {
                [ "$(docker volume inspect --format "{{index .Labels \"${LABEL_KEY}\"}}" -- "$1" 2>/dev/null)" \
                    = "${LABEL_VALUE}" ]
            }

            # Reads the whole UFW status once into a variable.
            #
            # `ufw status | grep -q` looks equivalent and is not: `grep -q`
            # exits at its first match, ufw then writes into a closed pipe, and
            # `pipefail` turns the resulting SIGPIPE into a failure. That is
            # deterministic once the status exceeds the pipe buffer, so a busy
            # host reports "UFW is not active" and plans around rules it can
            # see perfectly well. Every match below runs against the variable.
            inspect_firewall() {
                local line

                if ! have ufw; then
                    firewall_state='unavailable'

                    return
                fi

                firewall_status="$(ufw status numbered 2>/dev/null)" || firewall_status=''
                firewall_state='inactive'

                while IFS= read -r line; do
                    if [[ "${line}" =~ ^[Ss]tatus:[[:space:]]+active[[:space:]]*$ ]]; then
                        firewall_state='active'

                        return
                    fi
                done <<<"${firewall_status}"
            }

            # This node's WireGuard address, which is the destination of both
            # Orbit rules. Without it their shape cannot be proved, so the
            # rules are reported rather than deleted.
            inspect_address() {
                local line

                while IFS= read -r line; do
                    if [[ "${line}" =~ inet[[:space:]]+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/ ]]; then
                        node_address="${BASH_REMATCH[1]}"

                        return
                    fi
                done <<<"$(ip -4 -o addr show dev "${INTERFACE}" 2>/dev/null)"
            }

            squeeze() {
                local value="$1"
                value="${value//$'\t'/ }"

                while [[ "${value}" == *'  '* ]]; do
                    value="${value//  / }"
                done

                printf '%s' "${value}"
            }

            rtrim() {
                local value="$1"
                printf '%s' "${value%"${value##*[![:space:]]}"}"
            }

            # Numbers the UFW rules whose comment is exactly the one asked for.
            # The comment ends the line, so the match is anchored there: a
            # prefix match would also claim a neighbour such as
            # `orbit:metrics-node-exporter-v2` and delete it silently.
            firewall_comment_numbers() {
                local comment="# $1"
                local line trimmed

                while IFS= read -r line; do
                    trimmed="$(rtrim "${line}")"

                    case "${trimmed}" in
                        *"${comment}")
                            if [[ "${trimmed}" =~ ^[[:space:]]*\[[[:space:]]*([0-9]+)\] ]]; then
                                printf '%s\n' "${BASH_REMATCH[1]}"
                            fi
                            ;;
                    esac
                done <<<"${firewall_status}"
            }

            # True when the numbered rule is the rule Orbit writes.
            #
            # The comment alone is not proof: a hand-edited rule that kept the
            # comment is drift the Gateway refuses, so the escape refuses it
            # too. Everything the Gateway compares is checked here except the
            # peer address, which is the Gateway's own and is exactly what is
            # unknowable with no Gateway.
            #
            # The destination is this node's WireGuard address. When the
            # interface has no IPv4 address that field cannot be checked
            # either way, so refusing on it would buy no safety and would
            # strand an operator on exactly the broken node this script exists
            # for. The destination must then still be a single IPv4 address,
            # every other field is still proved, and the report says which
            # rules were matched with the address unverified.
            firewall_rule_matches() {
                local number="$1" port="$2"
                local line trimmed body target source
                local address='[0-9]{1,3}(\.[0-9]{1,3}){3}'

                while IFS= read -r line; do
                    trimmed="$(rtrim "${line}")"

                    if [[ ! "${trimmed}" =~ ^[[:space:]]*\[[[:space:]]*${number}\][[:space:]]*(.*)$ ]]; then
                        continue
                    fi

                    body="${BASH_REMATCH[1]}"
                    body="$(rtrim "${body%%'#'*}")"

                    case "${body}" in
                        *'(v6)'*) return 1 ;;
                    esac

                    if [[ ! "${body}" =~ ^(.*[^[:space:]])[[:space:]]+ALLOW[[:space:]]+IN[[:space:]]+([^[:space:]]+)$ ]]; then
                        return 1
                    fi

                    target="$(squeeze "${BASH_REMATCH[1]}")"
                    source="${BASH_REMATCH[2]}"

                    if [ -n "${node_address}" ]; then
                        [[ "${target}" == "${node_address} ${port}/tcp on ${INTERFACE}" ]] || return 1
                    else
                        [[ "${target}" =~ ^${address}[[:space:]]${port}/tcp[[:space:]]on[[:space:]]${INTERFACE}$ ]] \
                            || return 1
                    fi

                    [[ "${source}" =~ ^${address}$ ]] || return 1

                    return 0
                done <<<"${firewall_status}"

                return 1
            }

            # Resolves one Orbit rule to `none`, `ok`, or `drift`, and prints
            # the rule number when it is `ok`.
            firewall_rule_plan() {
                local comment="$1" port="$2"
                local -a numbers=()
                local number

                while IFS= read -r number; do
                    [[ -n "${number}" ]] && numbers+=("${number}")
                done <<<"$(firewall_comment_numbers "${comment}")"

                if [ "${#numbers[@]}" -eq 0 ]; then
                    printf 'none\n'

                    return
                fi

                if [ "${#numbers[@]}" -ne 1 ]; then
                    printf 'drift\n'

                    return
                fi

                if ! firewall_rule_matches "${numbers[0]}" "${port}"; then
                    printf 'drift\n'

                    return
                fi

                printf 'ok %s\n' "${numbers[0]}"
            }

            remove_exporter() {
                local state="$1"

                if [ "${state}" = 'absent' ]; then
                    return
                fi

                if [ "${state}" = 'foreign' ]; then
                    return
                fi

                systemctl disable --now "${SERVICE}" >/dev/null 2>&1
                rm -f -- "${DROPIN}" "${DROPIN}${CANDIDATE_SUFFIX}"
                rmdir --ignore-fail-on-non-empty -- "${DROPIN_DIR}" 2>/dev/null
                systemctl daemon-reload >/dev/null 2>&1
                note_removed "${DROPIN} and the ${SERVICE} service it configured"
            }

            remove_containers() {
                local name

                for name in "$@"; do
                    if ! container_owned "${name}"; then
                        note_refused "container ${name} (lost its ${LABEL_FILTER} label)"
                        continue
                    fi

                    if docker container rm --force -- "${name}" >/dev/null 2>&1; then
                        note_removed "container ${name}"
                    else
                        note_refused "container ${name} (removal failed)"
                    fi
                done
            }

            remove_volumes() {
                local name

                for name in "$@"; do
                    if ! volume_owned "${name}"; then
                        note_refused "volume ${name} (lost its ${LABEL_FILTER} label)"
                        continue
                    fi

                    if docker volume rm -- "${name}" >/dev/null 2>&1; then
                        note_removed "volume ${name}"
                    else
                        note_refused "volume ${name} (removal failed)"
                    fi
                done
            }

            remove_configuration() {
                local state="$1"
                local path directory

                if [ "${state}" = 'absent' ]; then
                    return
                fi

                if [ "${state}" = 'foreign' ]; then
                    return
                fi

                for path in "${CONFIG_PATHS[@]}"; do
                    rm -f -- "${path}" "${path}${CANDIDATE_SUFFIX}"
                done

                rm -f -- "${MARKER_PATH}"

                for directory in "${CONFIG_DIRS_REVERSE[@]}"; do
                    rmdir --ignore-fail-on-non-empty -- "${directory}" 2>/dev/null
                done

                if [ -d "${CONFIG_DIR}" ]; then
                    note_refused "${CONFIG_DIR} (still holds files Orbit did not generate)"
                    return
                fi

                note_removed "${CONFIG_DIR} and every file Orbit generated in it"
            }

            # Deletes one approved rule, after proving the plan's number still
            # addresses the rule the operator approved.
            #
            # Re-verify is not re-plan. A UFW rule number is a position, not an
            # identity: if anything below the rule goes away between the plan
            # and the delete, the planned number addresses somebody else's
            # rule. So the numbering is re-read here and the planned number
            # must still resolve to the planned rule. When it does not, this
            # refuses and reports; it never retargets onto a new number,
            # because the operator approved a rule, not a number. This is the
            # same re-check `remove_containers` and `remove_volumes` make
            # through `container_owned` and `volume_owned`.
            remove_firewall_rule() {
                local number="$1" port="$2" comment="$3"
                local -a numbers=()
                local candidate

                inspect_firewall

                if [ "${firewall_state}" != 'active' ]; then
                    note_refused "UFW stopped being active before the rule commented ${comment} was removed; nothing was deleted."

                    return
                fi

                while IFS= read -r candidate; do
                    [ -n "${candidate}" ] && numbers+=("${candidate}")
                done <<<"$(firewall_comment_numbers "${comment}")"

                if [ "${#numbers[@]}" -ne 1 ] || [ "${numbers[0]}" != "${number}" ]; then
                    note_refused "the rules changed between the plan and the removal, so UFW rule [${number}] no longer addresses the rule commented ${comment}; nothing was deleted. Re-run to plan again."

                    return
                fi

                if ! firewall_rule_matches "${number}" "${port}"; then
                    note_refused "UFW rule [${number}] commented ${comment} no longer matches the rule Orbit writes; nothing was deleted."

                    return
                fi

                ufw --force delete "${number}" >/dev/null 2>&1

                # `ufw --force delete` exits 0 for a number that matched
                # nothing, so its status proves nothing. Read the rules back
                # and report what is actually gone.
                inspect_firewall

                if [ -n "$(firewall_comment_numbers "${comment}")" ]; then
                    note_refused "UFW rule commented ${comment} survived removal"

                    return
                fi

                note_removed "UFW rule commented ${comment}"
            }

            remove_firewall() {
                local -a entries=()
                local entry number port comment

                if [ "${exporter_rule_state}" = 'ok' ]; then
                    entries+=("${exporter_rule_number} ${EXPORTER_PORT} ${EXPORTER_COMMENT}")
                fi

                if [ "${grafana_rule_state}" = 'ok' ]; then
                    entries+=("${grafana_rule_number} ${GRAFANA_PORT} ${GRAFANA_COMMENT}")
                fi

                if [ "${#entries[@]}" -eq 0 ]; then
                    return
                fi

                # Highest number first: every delete renumbers the rules below
                # it, and Orbit's own second rule must not be the thing that
                # invalidates its own plan.
                while IFS= read -r entry; do
                    [ -n "${entry}" ] || continue
                    number="${entry%% *}"
                    entry="${entry#* }"
                    port="${entry%% *}"
                    comment="${entry#* }"

                    remove_firewall_rule "${number}" "${port}" "${comment}"
                done <<<"$(printf '%s\n' "${entries[@]}" | sort -rn)"
            }

            verify() {
                local containers volumes

                if [ "$(dropin_state)" = 'owned' ]; then
                    note_refused "${DROPIN} survived removal"
                fi

                if [ "$(configuration_state)" = 'owned' ]; then
                    note_refused "${CONFIG_DIR} survived removal"
                fi

                if docker_available; then
                    containers="$(orbit_containers)"
                    volumes="$(orbit_volumes)"

                    if [ -n "${containers}" ]; then
                        note_refused "Orbit Metrics containers survived removal: $(echo "${containers}" | tr '\n' ' ')"
                    fi

                    if [ -n "${volumes}" ]; then
                        note_refused "Orbit Metrics volumes survived removal: $(echo "${volumes}" | tr '\n' ' ')"
                    fi
                fi
            }

            # Everything Orbit leaves behind on purpose, and how to finish the job by hand.
            report_kept() {
                if [ "${scope}" != 'none' ]; then
                    note_kept "the ${PACKAGE} package: Orbit installed it but cannot prove it owns it, and removal through the Gateway leaves it installed too. This cleanup stops and disables its service and removes Orbit's drop-in, so the package is left inert. Remove it with: sudo apt-get purge --yes ${PACKAGE}"
                fi

                note_kept "this script at ${SELF}, so the cleanup stays re-runnable and verifiable. Remove it with: sudo rm -f ${SELF}"
                note_kept "Docker, its images and any container Orbit did not label ${LABEL_FILTER}"

                if [ "${scope}" = 'metrics-node' ]; then
                    note_kept "on the Gateway host: the metrics.orbit route, its certificate and its private DNS record. The Gateway reconciles them when it returns."
                fi

                note_kept "in the Gateway database: the Metrics role assignment, exporter preferences and stored credentials. Disable the role through Orbit once a Gateway is reachable."
            }

            finish() {
                if [ "${#refused[@]}" -ne 0 ]; then
                    printf '\nSome resources were left alone. Nothing was removed without proof.\n'

                    return 3
                fi

                printf '%s\n' "$1"

                return 0
            }

            report() {
                report_kept
                report_list 'Removed:' "${removed[@]}"
                report_list 'Left in place:' "${kept[@]}"
                report_list 'Proved with less evidence than usual:' "${downgrades[@]}"
                report_list 'Refused, because ownership could not be proved:' "${refused[@]}"
            }

            # Names every resource this run would remove, so the operator
            # confirms a list rather than a count, and `--dry-run` is a real
            # preview.
            plan_removals() {
                local dropin="$1" config="$2" containers="$3" volumes="$4"
                local name path

                if [ "${dropin}" = 'owned' ]; then
                    planned+=("${DROPIN}, and stop and disable ${SERVICE}")
                fi

                while IFS= read -r name; do
                    [ -n "${name}" ] && planned+=("container ${name}")
                done <<<"${containers}"

                while IFS= read -r name; do
                    [ -n "${name}" ] && planned+=("volume ${name}, and the data in it")
                done <<<"${volumes}"

                if [ "${config}" = 'owned' ]; then
                    for path in "${CONFIG_PATHS[@]}"; do
                        [ -e "${path}" ] && planned+=("${path}")
                    done

                    planned+=("${MARKER_PATH}")
                fi

                if [ "${exporter_rule_state}" = 'ok' ]; then
                    planned+=("UFW rule [${exporter_rule_number}] commented ${EXPORTER_COMMENT}$(unverified)")
                fi

                if [ "${grafana_rule_state}" = 'ok' ]; then
                    planned+=("UFW rule [${grafana_rule_number}] commented ${GRAFANA_COMMENT}$(unverified)")
                fi
            }

            unverified() {
                if [ -n "${node_address}" ]; then
                    return
                fi

                printf ' (destination address not verified)'
            }

            destination() {
                if [ -n "${node_address}" ]; then
                    printf '%s' "${node_address}"

                    return
                fi

                printf 'one IPv4 address'
            }

            plan_firewall() {
                local footprint="$1"

                if [ "${firewall_state}" != 'active' ]; then
                    exporter_rule_state='uninspectable'
                    grafana_rule_state='uninspectable'

                    # A node with no Metrics footprint at all has no rules to
                    # miss, so an inactive UFW is not a refusal there.
                    if [ "${footprint}" = 'yes' ]; then
                        note_refused 'UFW is not active, so Orbit Metrics firewall rules could not be inspected.'
                    fi

                    return
                fi

                read -r exporter_rule_state exporter_rule_number \
                    <<<"$(firewall_rule_plan "${EXPORTER_COMMENT}" "${EXPORTER_PORT}")"
                read -r grafana_rule_state grafana_rule_number \
                    <<<"$(firewall_rule_plan "${GRAFANA_COMMENT}" "${GRAFANA_PORT}")"

                if [ -z "${node_address}" ] \
                    && { [ "${exporter_rule_state}" = 'ok' ] || [ "${grafana_rule_state}" = 'ok' ]; }; then
                    note_downgrade "the ${INTERFACE} interface has no IPv4 address, so the destination address of the UFW rules below could not be verified. Everything else was matched: the Orbit comment at the end of the line, allow in on ${INTERFACE}, tcp, the expected port, a single IPv4 destination and a single IPv4 source."
                fi

                if [ "${exporter_rule_state}" = 'drift' ]; then
                    note_refused "UFW rules commented ${EXPORTER_COMMENT} are not the rule Orbit writes (allow in on ${INTERFACE} proto tcp from one IPv4 address to $(destination) port ${EXPORTER_PORT})"
                fi

                if [ "${grafana_rule_state}" = 'drift' ]; then
                    note_refused "UFW rules commented ${GRAFANA_COMMENT} are not the rule Orbit writes (allow in on ${INTERFACE} proto tcp from one IPv4 address to $(destination) port ${GRAFANA_PORT})"
                fi
            }

            main() {
                local argument
                local dropin config containers volumes footprint
                local answer
                local -a container_names=() volume_names=()

                for argument in "$@"; do
                    case "${argument}" in
                        --force) force=1 ;;
                        --dry-run) dry_run=1 ;;
                        --help|-h) usage; return 0 ;;
                        *) usage >&2; return 2 ;;
                    esac
                done

                if [ "$(id -u)" -ne 0 ]; then
                    printf 'orbit-metrics-uninstall must run as root: sudo %s\n' "${SELF}" >&2
                    return 4
                fi

                dropin="$(dropin_state)"
                config="$(configuration_state)"
                containers=''
                volumes=''

                if [ "${dropin}" = 'foreign' ]; then
                    note_refused "${DROPIN} (its first line is not '${DROPIN_MARKER}')"
                fi

                if [ "${config}" = 'foreign' ]; then
                    note_refused "${CONFIG_DIR} (${MARKER_PATH} does not read '${MARKER_CONTENTS}')"
                fi

                if docker_available; then
                    containers="$(orbit_containers)"
                    volumes="$(orbit_volumes)"
                elif [ "${config}" != 'absent' ]; then
                    note_refused 'Docker is not reachable, so Orbit Metrics containers and volumes could not be inspected.'
                fi

                footprint='no'

                if [ "${dropin}" != 'absent' ] || [ "${config}" != 'absent' ] \
                    || [ -n "${containers}" ] || [ -n "${volumes}" ]; then
                    footprint='yes'
                fi

                inspect_firewall
                inspect_address
                plan_firewall "${footprint}"

                if [ "${config}" = 'owned' ] || [ -n "${containers}" ] || [ -n "${volumes}" ]; then
                    scope='metrics-node'
                elif [ "${dropin}" = 'owned' ] || [ "${exporter_rule_state}" = 'ok' ]; then
                    scope='exporter'
                fi

                plan_removals "${dropin}" "${config}" "${containers}" "${volumes}"

                printf 'Orbit Metrics footprint on %s: %s\n' "$(uname -n)" "${scope}"
                printf '  exporter drop-in     %s\n' "${dropin}"
                printf '  generated config     %s\n' "${config}"
                printf '  labelled containers  %s\n' "$(printf '%s' "${containers}" | grep -c '^.' || true)"
                printf '  labelled volumes     %s\n' "$(printf '%s' "${volumes}" | grep -c '^.' || true)"
                printf '  exporter UFW rule    %s\n' "${exporter_rule_state}"
                printf '  grafana UFW rule     %s\n' "${grafana_rule_state}"

                report_list 'Proved with less evidence than usual:' "${downgrades[@]}"

                if [ "${dry_run}" -eq 1 ]; then
                    report_list 'Would remove:' "${planned[@]}"
                    report

                    finish $'\nDry run: nothing was changed.'

                    return
                fi

                report_list 'Will remove:' "${planned[@]}"

                if [ "${#planned[@]}" -eq 0 ]; then
                    report

                    finish $'\nNothing Orbit owns is left on this node.'

                    return
                fi

                if [ "${force}" -ne 1 ]; then
                    if [ ! -t 0 ]; then
                        printf '\nRefusing to run without a terminal. Re-run with --force.\n' >&2

                        return 2
                    fi

                    printf '\nRemove everything listed above? [y/N] '
                    read -r answer

                    case "${answer}" in
                        y|Y|yes|YES) ;;
                        *) printf 'Cancelled.\n'; return 2 ;;
                    esac
                fi

                remove_exporter "${dropin}"

                if [ -n "${containers}" ]; then
                    mapfile -t container_names <<<"${containers}"
                    remove_containers "${container_names[@]}"
                fi

                if [ -n "${volumes}" ]; then
                    mapfile -t volume_names <<<"${volumes}"
                    remove_volumes "${volume_names[@]}"
                fi

                remove_configuration "${config}"
                remove_firewall
                verify
                report

                finish $'\nEvery Orbit-owned Metrics resource on this node is gone.'
            }

            main "$@"
            BASH_WRAP;
    }
}
