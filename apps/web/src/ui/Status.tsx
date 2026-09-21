export type StatusProps = {
    value: string;
    /** For a node: whether it answered its last metrics scrape, or null when nothing scrapes it. */
    reach?: boolean | null;
};

/** What the status column shows: an active node that is scraped reads online or offline; every other state reads as itself. */
export const statusText = ({ value, reach }: StatusProps): string =>
    value === "active" && typeof reach === "boolean" ? (reach ? "online" : "offline") : value;

/** A status in its colour: green when it is known good, red offline or failed, yellow in between, and plain for an active node nothing checks. */
export function Status(props: StatusProps) {
    const text = statusText(props);
    const colour =
        text === "online" || (text === "active" && props.reach === undefined)
            ? "text-green"
            : text === "offline" || text === "failed"
              ? "text-red"
              : text === "active"
                ? ""
                : "text-yellow";

    return <span className={colour}>{text}</span>;
}

/** A compact status dot (green, red, yellow) to place before a name. */
export function StatusDot(props: StatusProps) {
    const text = statusText(props);
    const colour =
        text === "online" || (text === "active" && props.reach === undefined)
            ? "text-green"
            : text === "offline" || text === "failed"
              ? "text-red"
              : "text-yellow";

    return (
        <span
            className={`${colour} inline-block select-none mr-[1ch]`}
            title={text}
            aria-label={text}
        >
            ●
        </span>
    );
}
