import type { FirewallRule, LiveFirewallMatch } from "../api/types";
import { firewallHealthy } from "./fleet";

export type FirewallLineTone = "ok" | "warn" | "danger";

/** Drift is red. Missing desired rules and a failed operator rule are yellow. */
export function firewallLineTone(line: {
    match: LiveFirewallMatch | null;
    rule: FirewallRule | null;
}): FirewallLineTone {
    if (line.match === "drift" || line.match === "unmanaged") {
        return "danger";
    }

    if (line.match === "missing") {
        return "warn";
    }

    if (line.rule !== null && !firewallHealthy(line.rule)) {
        return "warn";
    }

    return "ok";
}

export function firewallPort(port: string, protocol: string): string {
    return port === "any" ? "any" : `${port}/${protocol}`;
}

export function firewallSource(source: string, iface: string | null | undefined): string {
    return iface === null || iface === undefined || iface === "" ? source : `${source} on ${iface}`;
}
