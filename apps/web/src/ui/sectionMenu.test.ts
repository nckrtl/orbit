import { describe, expect, it } from "vite-plus/test";
import { nextSectionIndex } from "./sectionMenu";

describe("section menu keys", () => {
    it("swaps two sections from either arrow", () => {
        expect(nextSectionIndex(0, 2, "ArrowDown", "vertical")).toBe(1);
        expect(nextSectionIndex(1, 2, "ArrowDown", "vertical")).toBe(0);
        expect(nextSectionIndex(0, 2, "ArrowUp", "vertical")).toBe(1);
        expect(nextSectionIndex(1, 2, "ArrowUp", "vertical")).toBe(0);
    });

    it("moves through three sections instead of toggling the first pair", () => {
        expect(nextSectionIndex(0, 3, "ArrowDown", "vertical")).toBe(1);
        expect(nextSectionIndex(1, 3, "ArrowDown", "vertical")).toBe(2);
        expect(nextSectionIndex(2, 3, "ArrowDown", "vertical")).toBe(0);
        expect(nextSectionIndex(0, 3, "ArrowUp", "vertical")).toBe(2);
        expect(nextSectionIndex(2, 3, "ArrowUp", "vertical")).toBe(1);
        expect(nextSectionIndex(1, 3, "Home", "vertical")).toBe(0);
        expect(nextSectionIndex(0, 3, "End", "vertical")).toBe(2);
    });

    it("uses left and right only when the menu is a row", () => {
        expect(nextSectionIndex(0, 3, "ArrowRight", "horizontal")).toBe(1);
        expect(nextSectionIndex(0, 3, "ArrowLeft", "horizontal")).toBe(2);
        expect(nextSectionIndex(1, 3, "ArrowDown", "horizontal")).toBeNull();
        expect(nextSectionIndex(1, 3, "ArrowRight", "vertical")).toBeNull();
    });

    it("stays on the only section", () => {
        expect(nextSectionIndex(0, 1, "ArrowDown", "vertical")).toBe(0);
        expect(nextSectionIndex(0, 1, "ArrowUp", "vertical")).toBe(0);
        expect(nextSectionIndex(0, 0, "Home", "vertical")).toBeNull();
    });
});
