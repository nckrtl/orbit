import type { components } from "../api/schema";

/** The update the Gateway runs on a node now, from the node's `updating` field. */
export type NodeUpdating = components["schemas"]["NodeUpdating"];

export type StatusProps = {
    value: string;
    /** For a node: whether it answered its last metrics scrape, or null when nothing scrapes it. */
    reach?: boolean | null;
    /** For a node: the update the Gateway runs on it now. It wins over every other state. */
    updating?: NodeUpdating | null;
};

const UPDATE_CAUSES: Record<NonNullable<NodeUpdating["kind"]>, string> = {
    fleet_rollout: "fleet rollout",
    gateway_release: "Gateway release",
};

/**
 * What the status column shows: a node that is updating reads updating; an active node that is scraped
 * reads online or offline; every other state reads as itself.
 */
export const statusText = ({ value, reach, updating }: StatusProps): string =>
    updating
        ? "updating"
        : value === "active" && typeof reach === "boolean"
          ? reach
              ? "online"
              : "offline"
          : value;

/** The hover text and the accessible label: the status, and for an updating node what updates it. */
export function statusLabel(props: StatusProps): string {
    const text = statusText(props);
    const kind = props.updating?.kind;

    return kind === undefined
        ? text
        : `${text} — ${UPDATE_CAUSES[kind] ?? kind.replace(/_/g, " ")}`;
}

/**
 * The colour of a status: blue while updating, green when known good, red offline or failed, and
 * yellow in between. `plainActive` leaves an active node that nothing checks uncoloured.
 */
export function statusColour(props: StatusProps, plainActive = false): string {
    const text = statusText(props);

    if (props.updating) return "text-blue";
    if (text === "online" || (text === "active" && props.reach === undefined)) return "text-green";
    if (text === "offline" || text === "failed") return "text-red";

    return text === "active" && plainActive ? "" : "text-yellow";
}

/** A status in its colour. An updating node names the cause on hover and to a screen reader. */
export function Status(props: StatusProps) {
    const text = statusText(props);
    const label = props.updating ? statusLabel(props) : undefined;

    return (
        <span className={statusColour(props, true)} title={label}>
            {text}
            {label !== undefined && <span className="sr-only">{label.slice(text.length)}</span>}
        </span>
    );
}

/** A compact status dot to place before a name. It pulses slowly while the node is updating. */
export function StatusDot(props: StatusProps) {
    const label = statusLabel(props);
    const pulse = props.updating ? " status-pulse" : "";

    return (
        <span
            role="img"
            className={`${statusColour(props)}${pulse} inline-block select-none mr-[1ch]`}
            title={label}
            aria-label={label}
        >
            ●
        </span>
    );
}
