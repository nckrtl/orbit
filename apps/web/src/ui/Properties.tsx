import type { ReactNode } from "react";
import { Frame } from "./Frame";

export type Property = {
    name: string;
    value: string | number | boolean | string[] | null | undefined;
    title?: string;
    warn?: boolean;
    onOpen?: () => void;
    /** Rich value rendered instead of the plain text when set. */
    node?: ReactNode;
};

const text = (value: Property["value"]): string => {
    if (value === null || value === undefined || value === "") {
        return "—";
    }

    if (typeof value === "boolean") {
        return value ? "yes" : "no";
    }

    return Array.isArray(value) ? value.join(", ") : String(value);
};

/** The properties a page lists, named as the show commands name them. */
export function Properties({
    properties,
    children,
    className,
    title = "Properties",
    testId,
}: {
    properties: Property[];
    children?: ReactNode;
    className?: string;
    title?: string;
    /** Stable id for web verification. Omitted when unset. */
    testId?: string;
}) {
    return (
        <Frame
            title={title}
            className={`w-full ${className ?? ""}`.trim()}
            bodyClassName="properties"
            testId={testId}
        >
            {properties.map((property) => {
                const value = text(property.value);

                return (
                    <div
                        key={property.name}
                        className="row"
                        style={{ gridTemplateColumns: "minmax(12ch, 18ch) minmax(0, 1fr)" }}
                    >
                        <span className="text-dim">{property.name}</span>
                        {property.node !== undefined ? (
                            <span className="selectable" title={property.title ?? value}>
                                {property.node}
                            </span>
                        ) : property.onOpen !== undefined && value !== "—" ? (
                            <span className="link" onClick={property.onOpen}>
                                {value}
                            </span>
                        ) : (
                            <span
                                className={`selectable ${property.warn ? "text-yellow" : ""}`}
                                title={property.title ?? value}
                            >
                                {value}
                            </span>
                        )}
                    </div>
                );
            })}
            {children}
        </Frame>
    );
}
