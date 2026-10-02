export function resolveToolbarFontSize(value?: string | number): string {
    return value === "small" || value === "large" ? value : "sm";
}
