import { Frame } from "./Frame";

export type Property = {
    name: string;
    value: string | number | boolean | string[] | null | undefined;
    warn?: boolean;
    onOpen?: () => void;
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
    className,
    title = "Properties",
}: {
    properties: Property[];
    className?: string;
    title?: string;
}) {
    return (
        <Frame title={title} className={`w-full ${className ?? ""}`.trim()}>
            {properties.map((property) => {
                const value = text(property.value);

                return (
                    <div
                        key={property.name}
                        className="row"
                        style={{ gridTemplateColumns: "minmax(12ch, 18ch) minmax(0, 1fr)" }}
                    >
                        <span className="text-dim">{property.name}</span>
                        {property.onOpen !== undefined && value !== "—" ? (
                            <span className="link" onClick={property.onOpen}>
                                {value}
                            </span>
                        ) : (
                            <span
                                className={`selectable ${property.warn ? "text-yellow" : ""}`}
                                title={value}
                            >
                                {value}
                            </span>
                        )}
                    </div>
                );
            })}
        </Frame>
    );
}
