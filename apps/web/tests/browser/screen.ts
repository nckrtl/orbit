/**
 * The screen as text: one block per frame with the labels
 * from its border, then one line per row with its cells. It reads structure, not pixels, so it is
 * the same on every operating system. A bar is its reading only, a selected row starts with `›` (the page shows it by highlight alone),
 * and a row that needs a look ends with `!`.
 */
export function screenText(root: ParentNode = document): string {
    const text = (element: Element): string => {
        const copy = element.cloneNode(true) as Element;
        copy.querySelectorAll("[aria-hidden]").forEach((hidden) => hidden.remove());

        return (copy.textContent ?? "").replace(/\s+/g, " ").trim();
    };
    const labels = (frame: Element, edge: string): string =>
        [...frame.querySelectorAll(`:scope > .frame-edge[data-edge="${edge}"] .frame-label`)]
            .map(text)
            .join(" ── ");

    const lines: string[] = [];

    for (const frame of root.querySelectorAll(".frame")) {
        const body = frame.querySelector(":scope > .frame-body") as Element;
        const rows = [...body.querySelectorAll(".row")].filter(
            (candidate) => candidate.closest(".frame") === frame,
        );

        lines.push(`╭─ ${labels(frame, "top")}`.trimEnd());

        const meters = [...body.querySelectorAll('[role="meter"]')];

        if (rows.length === 0 && meters.length > 0) {
            lines.push(...meters.map((meter) => `│  ${text(meter)}`));
        } else if (rows.length === 0) {
            lines.push(`│ ${text(body)}`);
        }

        for (const candidate of rows) {
            const cells = [...candidate.children].map(text);
            // The page marks the selected row with its highlight; here it is a `›`.
            const marker = candidate.hasAttribute("data-selected") ? "›" : " ";
            lines.push(
                `│${marker} ${cells.join(" │ ")}${candidate.hasAttribute("data-warn") ? " !" : ""}`.trimEnd(),
            );
        }

        lines.push(`╰─ ${labels(frame, "bottom")}`.trimEnd(), "");
    }

    lines.push([...(root.querySelector("footer") as Element).children].map(text).join("    "));

    return `${lines.join("\n")}\n`;
}
