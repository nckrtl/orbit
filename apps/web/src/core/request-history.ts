/** Orbit web has no Laravel toolbar request history; Annotation context still works without it. */
export type ToolbarData = {
    primary_color?: string;
    primary_text_color?: string;
    font_size?: string | number;
    request?: {
        controller_action?: string | null;
        route_name?: string | null;
    };
};

const empty: ToolbarData = {
    primary_color: "#3b82f6",
    primary_text_color: "#ffffff",
    font_size: "sm",
    request: {},
};

export function getActiveToolbarData(): ToolbarData {
    return empty;
}
