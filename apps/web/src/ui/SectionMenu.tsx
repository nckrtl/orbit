import { useSyncExternalStore } from "react";
import { Frame } from "./Frame";
import { nextSectionIndex, type SectionOrientation } from "./sectionMenu";

const desktopQuery = "(min-width: 768px)";

function subscribeDesktop(notify: () => void): () => void {
    const media = window.matchMedia(desktopQuery);
    media.addEventListener("change", notify);

    return () => media.removeEventListener("change", notify);
}

/** The width where a section menu can sit beside its page. Same breakpoint as `md`. */
function useDesktop(): boolean {
    return useSyncExternalStore(
        subscribeDesktop,
        () => window.matchMedia(desktopQuery).matches,
        () => true,
    );
}

export type SectionMenuItem<Id extends string> = {
    id: Id;
    label: string;
    testId: string;
    /** A count drawn after the label, such as an instance's tasks. Omitted when zero. */
    count?: number;
};

/**
 * The section menu an Instance or a Node uses. On a wide screen it is a vertical tab list.
 * A responsive menu becomes one row on a narrow screen so the page beside it keeps its width.
 */
export function SectionMenu<Id extends string>({
    label,
    ariaLabel,
    idPrefix,
    items,
    selected,
    onSelect,
    responsive = false,
}: {
    label: string;
    ariaLabel: string;
    idPrefix: string;
    items: readonly SectionMenuItem<Id>[];
    selected: Id;
    onSelect: (id: Id) => void;
    /** Narrow screens lay the tabs in one row. A fixed menu stays vertical. */
    responsive?: boolean;
}) {
    const desktop = useDesktop();
    const orientation: SectionOrientation = responsive && !desktop ? "horizontal" : "vertical";
    const selectedIndex = Math.max(
        0,
        items.findIndex((item) => item.id === selected),
    );

    return (
        <Frame
            title="Menu"
            label={label}
            className={
                responsive
                    ? "w-full shrink-0 md:w-[16ch] md:min-h-0 md:self-stretch"
                    : "w-[16ch] min-h-0 shrink-0 self-stretch"
            }
        >
            <div
                role="tablist"
                aria-label={ariaLabel}
                aria-orientation={orientation}
                className={`flex gap-1 ${orientation === "horizontal" ? "flex-row" : "flex-col"}`}
            >
                {items.map((item) => (
                    <button
                        key={item.id}
                        id={`${idPrefix}-${item.id}-tab`}
                        data-testid={item.testId}
                        role="tab"
                        type="button"
                        aria-selected={selected === item.id}
                        aria-controls={`${idPrefix}-${item.id}-panel`}
                        tabIndex={selected === item.id ? 0 : -1}
                        className={`row nav-row text-left focus-visible:outline-2 focus-visible:outline-cyan ${orientation === "horizontal" ? "shrink-0" : ""}`}
                        style={{ gridTemplateColumns: "1fr auto" }}
                        data-link=""
                        data-selected={selected === item.id ? "" : undefined}
                        data-focused=""
                        onClick={() => onSelect(item.id)}
                        onKeyDown={(event) => {
                            const nextIndex = nextSectionIndex(
                                selectedIndex,
                                items.length,
                                event.key,
                                orientation,
                            );

                            if (nextIndex === null) {
                                return;
                            }

                            event.preventDefault();
                            event.stopPropagation();
                            const next = items[nextIndex];

                            if (next === undefined || nextIndex === selectedIndex) {
                                return;
                            }

                            onSelect(next.id);
                            event.currentTarget.parentElement
                                ?.querySelectorAll<HTMLButtonElement>('[role="tab"]')
                                [nextIndex]?.focus();
                        }}
                    >
                        <span>{item.label}</span>
                        {item.count !== undefined && item.count > 0 && (
                            <span className="font-normal">{item.count}</span>
                        )}
                    </button>
                ))}
            </div>
        </Frame>
    );
}
