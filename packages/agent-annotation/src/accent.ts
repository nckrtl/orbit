import { useToolbar } from "./composables/useToolbar";

const FALLBACK = "#3b82f6";
const FALLBACK_TEXT = "#ffffff";

export function useAnnotationAccent() {
    const { data } = useToolbar();

    return {
        color: data.primary_color || FALLBACK,
        textColor: data.primary_text_color || FALLBACK_TEXT,
    };
}
