/** A record's status in its colour: green when it is active, red when it failed, yellow while it is anything else. */
export function Status({ value }: { value: string }) {
    const colour =
        value === "active" ? "text-green" : value === "failed" ? "text-red" : "text-yellow";

    return <span className={colour}>{value}</span>;
}
