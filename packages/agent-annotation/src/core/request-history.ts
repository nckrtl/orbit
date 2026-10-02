/** Optional host context; no dependency on Laravel or Orbit. */
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

let getData: (() => ToolbarData) | undefined;

export function configureToolbarData(callback?: () => ToolbarData): void {
    getData = callback;
}

export function getActiveToolbarData(): ToolbarData {
    return getData?.() ?? empty;
}
