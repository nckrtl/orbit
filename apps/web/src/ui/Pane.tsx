import { useLocation } from "@tanstack/react-router";
import {
    createSortedRowModel,
    rowSortingFeature,
    sortFns,
    tableFeatures,
    useTable,
} from "@tanstack/react-table";
import { type CSSProperties, Fragment, useEffect, useMemo, useRef } from "react";
import { Frame, Note } from "./Frame";
import { useGo } from "./go";
import { openMenu } from "./menu";
import { panes, selectionKey, type Target, ui, useUi } from "./store";

const features = tableFeatures({
    rowSortingFeature,
    sortedRowModel: createSortedRowModel(),
    sortFns,
});

export type Column<T> = {
    header: string;
    /** Share of the pane's width. */
    width: number;
    /** Size the column to its widest cell, and leave the shares to the others. */
    fit?: boolean;
    /** Right-align the header and cells; the last column is always right-aligned. */
    align?: "right";
    value: (row: T) => string;
    /** What the cell draws, when that is more than its text. */
    cell?: (row: T) => React.ReactNode;
    /** What the column sorts by, when that is not the text it shows. */
    sort?: (row: T) => string | number;
    /** Hide this column on mobile screens (< 768px). */
    hideOnMobile?: boolean;
};

type PaneProps<T> = {
    name: string;
    order: number;
    title: string;
    columns: Column<T>[];
    rows: T[];
    rowId: (row: T) => string;
    warn?: (row: T) => boolean;
    /** Drift or another failure that must read as red, not yellow. */
    danger?: (row: T) => boolean;
    /** The record a row opens and acts on; omit for a leaf pane whose rows go nowhere. */
    target?: (row: T) => Target | null;
    /** What a click on a row does when the row is not a record, such as opening a page elsewhere. */
    onRowClick?: (row: T) => void;
    bottomLeft?: React.ReactNode;
    bottomRight?: React.ReactNode;
    empty?: string;
    /** Rows that belong below a labelled divider; each group keeps the sort within itself. */
    divide?: { label: string; below: (row: T) => boolean };
    topRight?: React.ReactNode;
    className?: string;
    /** Stable id for web verification. Omitted when unset. */
    testId?: string;
};

/**
 * A framed, sortable, scrollable table. Arrow keys hover it, Enter focuses it, and a focused pane
 * moves its selection; a click or Enter opens a row, and a right click
 * lists its actions. A column with `align: "right"` and the last column are right-aligned.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function Pane<T extends Record<string, any>>({
    name,
    order,
    title,
    columns,
    rows,
    rowId,
    warn,
    danger,
    target,
    onRowClick,
    bottomLeft,
    bottomRight,
    empty = "None.",
    divide,
    topRight,
    className,
    testId,
}: PaneProps<T>) {
    const go = useGo();
    const key = selectionKey(useLocation().pathname, name);
    const focused = useUi((state) => state.focus === name);
    const hovered = useUi((state) => state.focus === null && state.hover === name);
    const selectedIndex = useUi((state) => state.selected[key] ?? 0);
    const selectedRow = useRef<HTMLDivElement>(null);

    const definitions = useMemo(
        () =>
            columns.map((column, index) => ({
                id: String(index),
                header: column.header,
                accessorFn: (row: T) => (column.sort ?? column.value)(row),
            })),
        [columns],
    );
    const table = useTable<typeof features, T>({
        features,
        columns: definitions,
        data: rows,
        getRowId: rowId,
    });
    const model = table.getRowModel().rows;
    const above = divide === undefined ? model : model.filter((row) => !divide.below(row.original));
    const sorted = [...above, ...model.filter((row) => !above.includes(row))];
    const dividerAt = above.length > 0 && above.length < sorted.length ? above.length : -1;
    const selected = Math.min(selectedIndex, Math.max(0, sorted.length - 1));

    const desktopTemplate = columns
        .map((column) => (column.fit ? "max-content" : `minmax(0, ${column.width}fr)`))
        .join(" ");
    const mobileColumns = columns.filter((column) => !column.hideOnMobile);
    const mobileTemplate = mobileColumns
        .map((column) => (column.fit ? "max-content" : `minmax(0, ${column.width}fr)`))
        .join(" ");
    const lastMobileIndex = columns.findLastIndex((column) => !column.hideOnMobile);

    useEffect(() => {
        panes.set(name, {
            order,
            count: sorted.length,
            leaf: target === undefined,
            target: (index) => {
                const row = sorted[index]?.original;

                return row === undefined || target === undefined ? null : target(row);
            },
            activate:
                onRowClick === undefined
                    ? undefined
                    : (index) => {
                          const row = sorted[index]?.original;
                          if (row !== undefined) onRowClick(row);
                      },
        });

        return () => void panes.delete(name);
    });

    useEffect(() => {
        if (focused) {
            selectedRow.current?.scrollIntoView({ block: "nearest" });
        }
    }, [focused, selected]);

    // A click is a click on the record: it selects the row and opens it. The selection stays, so
    // going back finds the row that was opened. A leaf pane's rows go nowhere, so they only select.
    const click = (index: number, row: T) => {
        ui.set({ hover: name, focus: name });
        ui.select(key, index);
        const found = target?.(row) ?? null;

        if (found !== null) {
            go.record(found.kind, found.row);
        }

        onRowClick?.(row);
    };

    const context = (index: number, row: T, event: React.MouseEvent) => {
        event.preventDefault();
        ui.set({ hover: name, focus: name });
        ui.select(key, index);
        const found = target?.(row) ?? null;

        if (found !== null) {
            openMenu(found, [event.clientX, event.clientY]);
        }
    };

    return (
        <Frame
            title={title}
            topRight={topRight}
            bottomLeft={bottomLeft}
            bottomRight={bottomRight}
            state={focused ? "focused" : hovered ? "hovered" : undefined}
            className={className}
            pane={name}
            testId={testId}
            onMouseDown={() => ui.set({ hover: name, focus: name })}
        >
            {sorted.length === 0 ? (
                <Note>{empty}</Note>
            ) : (
                <div
                    role="table"
                    aria-label={title}
                    className="table-grid"
                    style={
                        {
                            "--grid-cols-mobile": mobileTemplate,
                            "--grid-cols-desktop": desktopTemplate,
                        } as CSSProperties
                    }
                >
                    <div className="row" role="row" data-head>
                        {table.getHeaderGroups()[0]?.headers.map((header, index) => {
                            const column = columns[index];
                            const isLast = index === columns.length - 1;
                            const isMobileLast = index === lastMobileIndex;

                            return (
                                <span
                                    key={header.id}
                                    role="columnheader"
                                    className={`cursor-pointer hover:text-fg ${
                                        column?.align === "right" || isLast
                                            ? "text-right"
                                            : isMobileLast
                                              ? "text-right md:text-left"
                                              : ""
                                    } ${column?.hideOnMobile ? "hidden md:block" : ""}`}
                                    onMouseDown={(event) => event.stopPropagation()}
                                    onClick={header.column.getToggleSortingHandler()}
                                >
                                    {column?.header}
                                    {{ asc: " ▴", desc: " ▾" }[
                                        header.column.getIsSorted() as string
                                    ] ?? ""}
                                </span>
                            );
                        })}
                    </div>
                    {sorted.map((row, index) => (
                        <Fragment key={row.id}>
                            {index === dividerAt && (
                                <div className="divider" role="presentation">
                                    {divide?.label}
                                </div>
                            )}
                            <div
                                ref={index === selected ? selectedRow : undefined}
                                className="row"
                                role="row"
                                aria-selected={index === selected}
                                data-link={
                                    target === undefined && onRowClick === undefined
                                        ? undefined
                                        : ""
                                }
                                data-selected={index === selected ? "" : undefined}
                                data-focused={focused ? "" : undefined}
                                data-warn={
                                    danger?.(row.original)
                                        ? undefined
                                        : warn?.(row.original)
                                          ? ""
                                          : undefined
                                }
                                data-danger={danger?.(row.original) ? "" : undefined}
                                onMouseDown={(event) => {
                                    event.stopPropagation();

                                    if (event.button === 0) {
                                        click(index, row.original);
                                    }
                                }}
                                onContextMenu={(event) => context(index, row.original, event)}
                            >
                                {columns.map((column, cell) => {
                                    const isLast = cell === columns.length - 1;
                                    const isMobileLast = cell === lastMobileIndex;

                                    return (
                                        <span
                                            key={cell}
                                            role="cell"
                                            className={`min-w-0 ${
                                                column.align === "right" || isLast
                                                    ? "text-right"
                                                    : isMobileLast
                                                      ? "text-right md:text-left"
                                                      : ""
                                            } ${column.hideOnMobile ? "hidden md:block" : ""}`}
                                        >
                                            {column.cell?.(row.original) ??
                                                column.value(row.original)}
                                        </span>
                                    );
                                })}
                            </div>
                        </Fragment>
                    ))}
                </div>
            )}
        </Frame>
    );
}
