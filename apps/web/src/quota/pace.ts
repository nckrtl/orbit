import type { QuotaAccount, QuotaWindow } from "../api/types";

/** Only explicit fixed-duration labels are safe without a window start timestamp. */
export function expectedRemaining(window: QuotaWindow, now: number): number | null {
    const label = window.label.toLowerCase();
    const duration = label.match(/(?:^|\s|·)(\d+)(h|d|[- ]hour|[- ]day)\b/);
    let hours: number | null = null;
    if (duration) hours = Number(duration[1]) * (duration[2]!.includes("d") ? 24 : 1);
    else if (/\bweekly\b/.test(label)) hours = 168;
    else if (/\bdaily\b/.test(label)) hours = 24;
    if (!hours || !window.resets_at) return null;
    const remaining = Date.parse(window.resets_at) - now;
    const total = hours * 3_600_000;
    if (!Number.isFinite(remaining) || remaining <= 0 || remaining > total) return null;
    return (remaining / total) * 100;
}

/** Match the collector's equal-weight average of enabled accounts for this window. */
export function quotaPace(
    window: QuotaWindow,
    now: number,
    accounts?: QuotaAccount[],
): number | null {
    if (!accounts) return expectedRemaining(window, now);
    const members = accounts
        .filter((account) => !account.disabled)
        .flatMap((account) => account.windows.filter((member) => member.label === window.label));
    if (!members.length) return null;
    const values = members.map((member) => expectedRemaining(member, now));
    if (values.some((value) => value === null)) return null;
    return values.reduce<number>((sum, value) => sum + value!, 0) / values.length;
}
