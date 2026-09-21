import { expect, it } from "vite-plus/test";
import type { QuotaAccount, QuotaWindow } from "../api/types";
import { expectedRemaining, quotaPace } from "./pace";
const now = Date.parse("2026-09-21T12:00:00Z");
const window = (label: string, hours: number): QuotaWindow => ({
    label,
    used_percent: 40,
    remaining_percent: 60,
    resets_at: new Date(now + hours * 3_600_000).toISOString(),
});
it("tracks fixed windows in the remaining direction", () => {
    expect(expectedRemaining(window("5h", 5), now)).toBe(100);
    expect(expectedRemaining(window("5h", 2.5), now)).toBe(50);
    expect(expectedRemaining(window("5h", 0.05), now)).toBeCloseTo(1);
    for (const label of ["7d", "7-day Fable 5", "Gemini models · Weekly limit"])
        expect(expectedRemaining(window(label, 84), now)).toBe(50);
    expect(expectedRemaining(window("Claude and GPT models · 5-hour limit", 2.5), now)).toBe(50);
    expect(expectedRemaining(window("Daily limit", 12), now)).toBe(50);
});
it("omits unknown, missing, invalid, expired, and out-of-window resets", () => {
    expect(expectedRemaining(window("Monthly credits", 24), now)).toBeNull();
    expect(expectedRemaining({ ...window("7d", 84), resets_at: null }, now)).toBeNull();
    expect(expectedRemaining({ ...window("7d", 84), resets_at: "invalid" }, now)).toBeNull();
    for (const hours of [-1, 0, 169])
        expect(expectedRemaining(window("7d", hours), now)).toBeNull();
});
it("averages contributing accounts rather than the earliest pool reset", () => {
    const account = (hours: number, disabled = false): QuotaAccount => ({
        id: String(hours),
        provider: "codex",
        label: "test",
        status: "ok",
        error: null,
        disabled,
        windows: [window("7d", hours)],
    });
    const accounts = [account(42), account(126), account(1, true)];
    expect(quotaPace(window("7d", 42), now, accounts)).toBe(50);
    expect(quotaPace(window("7d", 42), now, [...accounts, account(0)])).toBeNull();
    expect(quotaPace(window("7d", 42), now, [])).toBeNull();
});
