import { useLocation } from "@tanstack/react-router";
import {
    createSortedRowModel,
    rowSortingFeature,
    sortFns,
    tableFeatures,
    useTable,
} from "@tanstack/react-table";
import { Fragment, useEffect, useMemo, useRef } from "react";
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
    /** Share of the pane's width, as the `orbit top` tables give their columns. */
    width: number;
    /** Size the column to its widest cell, and leave the shares to the others. */
    fit?: boolean;
    value: (row: T) => string;
    /** What the cell draws, when that is more than its text. */
    cell?: (row: T) => React.ReactNode;
    /** What the column sorts by, when that is not the text it shows. */
    sort?: (row: T) => string | number;
};

type PaneProps<T> = {
    name: string;
    order: number;
    title: string;
    columns: Column<T>[];
    rows: T[];
    rowId: (row: T) => string;
    warn?: (row: T) => boolean;
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
};

/**
 * A framed, sortable, scrollable table. Arrow keys hover it, Enter focuses it, and a focused pane
 * moves its selection; a click or Enter opens a row, and a right click
 * lists its actions. The last column is right-aligned.
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
    target,
    onRowClick,
    bottomLeft,
    bottomRight,
    empty = "None.",
    divide,
    topRight,
    className,
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
    const template = columns
        .map((column) => (column.fit ? "max-content" : `minmax(0, ${column.width}fr)`))
        .join(" ");

    useEffect(() => {
        panes.set(name, {
            order,
            count: sorted.length,
            leaf: target === undefined,
            target: (index) => {
                const row = sorted[index]?.original;

                return row === undefined || target === undefined ? null : target(row);
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
            onMouseDown={() => ui.set({ hover: name, focus: name })}
        >
            {sorted.length === 0 ? (
                <Note>{empty}</Note>
            ) : (
                <div
                    role="table"
                    aria-label={title}
                    className="table-grid"
                    style={{ gridTemplateColumns: template }}
                >
                    <div className="row" role="row" data-head>
                        {table.getHeaderGroups()[0]?.headers.map((header, index) => (
                            <span
                                key={header.id}
                                role="columnheader"
                                className={`cursor-pointer hover:text-fg ${index === columns.length - 1 ? "text-right" : ""}`}
                                onMouseDown={(event) => event.stopPropagation()}
                                onClick={header.column.getToggleSortingHandler()}
                            >
                                {columns[index]?.header}
                                {{ asc: " ▴", desc: " ▾" }[header.column.getIsSorted() as string] ??
                                    ""}
                            </span>
                        ))}
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
                                data-warn={warn?.(row.original) ? "" : undefined}
                                onMouseDown={(event) => {
                                    event.stopPropagation();

                                    if (event.button === 0) {
                                        click(index, row.original);
                                    }
                                }}
                                onContextMenu={(event) => context(index, row.original, event)}
                            >
                                {columns.map((column, cell) => (
                                    <span
                                        key={cell}
                                        role="cell"
                                        className={`min-w-0 ${cell === columns.length - 1 ? "text-right" : ""}`}
                                    >
                                        {column.cell?.(row.original) ?? column.value(row.original)}
                                    </span>
                                ))}
                            </div>
                        </Fragment>
                    ))}
                </div>
            )}
        </Frame>
    );
}
