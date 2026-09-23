import { getActiveToolbarData, type ToolbarData } from "../core/request-history";

export function useToolbar(): { data: ToolbarData } {
    return { data: getActiveToolbarData() };
}
