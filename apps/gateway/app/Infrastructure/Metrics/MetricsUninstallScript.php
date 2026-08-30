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
            # Re-enabling is ordinary registration: run `orbit metrics:enable` once a
            # Gateway is reachable again.
            #
            # Usage: sudo @@SELF@@ [--force] [--dry-run]
            #
            # Exit codes: 0 removed everything Orbit owns, 2 usage, 3 something was
            # refused or survived removal, 4 must run as root.

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

            readonly CONFIG_PATHS=(
            @@CONFIG_PATHS@@
            )

            readonly CONFIG_DIRS_REVERSE=(
            @@CONFIG_DIRS_REVERSE@@
            )

            removed=()
            refused=()
            kept=()
            force=0
            dry_run=0
            scope='none'

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

            firewall_available() {
                have ufw && ufw status 2>/dev/null | grep -qiE '^Status:[[:space:]]+active$'
            }

            # Numbers the UFW rules whose comment is exactly the one asked for. The
            # comment ends the line, so the match is anchored there: a prefix match would
            # also claim a future neighbour such as `orbit:metrics-node-exporter-v2` and
            # delete it silently.
            firewall_rule_numbers() {
                local comment="# $1"
                local line trimmed

                ufw status numbered 2>/dev/null | while IFS= read -r line; do
                    trimmed="${line%"${line##*[![:space:]]}"}"

                    case "${trimmed}" in
                        *"${comment}")
                            if [[ "${trimmed}" =~ ^[[:space:]]*\[[[:space:]]*([0-9]+)\] ]]; then
                                printf '%s\n' "${BASH_REMATCH[1]}"
                            fi
                            ;;
                    esac
                done
            }

            firewall_rule_count() {
                firewall_rule_numbers "$1" | grep -c '^' 2>/dev/null
            }

            # Deletes rules one at a time, re-reading the numbering between deletes
            # because every delete renumbers the rules below it.
            remove_firewall_rules() {
                local comment="$1"
                local attempt=0
                local number

                while [ "${attempt}" -lt 32 ]; do
                    number="$(firewall_rule_numbers "${comment}" | head -n 1)"

                    if [ -z "${number}" ]; then
                        return 0
                    fi

                    if ! ufw --force delete "${number}" >/dev/null 2>&1; then
                        return 1
                    fi

                    attempt=$((attempt + 1))
                done

                return 1
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

            remove_firewall() {
                local comment

                for comment in "${EXPORTER_COMMENT}" "${GRAFANA_COMMENT}"; do
                    if [ "$(firewall_rule_count "${comment}")" -eq 0 ]; then
                        continue
                    fi

                    if remove_firewall_rules "${comment}"; then
                        note_removed "UFW rules commented ${comment}"
                    else
                        note_refused "UFW rules commented ${comment} (removal failed)"
                    fi
                done
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

                if firewall_available; then
                    if [ "$(firewall_rule_count "${EXPORTER_COMMENT}")" -ne 0 ]; then
                        note_refused "UFW rules commented ${EXPORTER_COMMENT} survived removal"
                    fi

                    if [ "$(firewall_rule_count "${GRAFANA_COMMENT}")" -ne 0 ]; then
                        note_refused "UFW rules commented ${GRAFANA_COMMENT} survived removal"
                    fi
                fi
            }

            # Everything Orbit leaves behind on purpose, and how to finish the job by hand.
            report_kept() {
                if [ "${scope}" != 'none' ]; then
                    note_kept "the ${PACKAGE} package: Orbit installed it but cannot prove it owns it, and another scrape may need it. Remove it with: sudo apt-get purge --yes ${PACKAGE}"
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
                report_list 'Refused, because ownership could not be proved:' "${refused[@]}"
            }

            main() {
                local argument
                local dropin config containers volumes
                local exporter_rules='0' grafana_rules='0'
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

                if firewall_available; then
                    exporter_rules="$(firewall_rule_count "${EXPORTER_COMMENT}")"
                    grafana_rules="$(firewall_rule_count "${GRAFANA_COMMENT}")"
                else
                    note_refused 'UFW is not active, so Orbit Metrics firewall rules could not be inspected.'
                fi

                if [ "${config}" = 'owned' ] || [ -n "${containers}" ] || [ -n "${volumes}" ]; then
                    scope='metrics-node'
                elif [ "${dropin}" = 'owned' ] || [ "${exporter_rules}" -ne 0 ]; then
                    scope='exporter'
                fi

                printf 'Orbit Metrics footprint on %s: %s\n' "$(uname -n)" "${scope}"
                printf '  exporter drop-in     %s\n' "${dropin}"
                printf '  generated config     %s\n' "${config}"
                printf '  labelled containers  %s\n' "$(printf '%s' "${containers}" | grep -c '^.' || true)"
                printf '  labelled volumes     %s\n' "$(printf '%s' "${volumes}" | grep -c '^.' || true)"
                printf '  exporter UFW rules   %s\n' "${exporter_rules}"
                printf '  grafana UFW rules    %s\n' "${grafana_rules}"

                if [ "${scope}" = 'none' ]; then
                    report

                    finish $'\nNothing Orbit owns is left on this node.'

                    return
                fi

                if [ "${dry_run}" -eq 1 ]; then
                    report

                    finish $'\nDry run: nothing was changed.'

                    return
                fi

                if [ "${force}" -ne 1 ]; then
                    if [ ! -t 0 ]; then
                        printf '\nRefusing to run without a terminal. Re-run with --force.\n' >&2

                        return 2
                    fi

                    printf '\nThis removes the resources above, including their data volumes. Continue? [y/N] '
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
