import { describe, expect, it } from "vite-plus/test";
import { describeCron } from "./cron";

describe("describeCron", () => {
    it.each([
        [null, "On demand"],
        ["*/15 * * * *", "Every 15 minutes"],
        ["0 * * * *", "Hourly"],
        ["30 * * * *", "Hourly at :30"],
        ["0 3 * * *", "Daily at 03:00 UTC"],
        ["0 9 * * 1-5", "Weekdays at 09:00 UTC"],
        ["0 3 * * 1", "Weekly on Monday at 03:00 UTC"],
        ["0 3 * * 0", "Weekly on Sunday at 03:00 UTC"],
        ["15 4 1 * *", "Monthly on the 1st at 04:15 UTC"],
        ["0 0 1 1 *", "0 0 1 1 *"],
        ["0 3 * * 1,3", "0 3 * * 1,3"],
    ])("describes %s", (expression, words) => {
        expect(describeCron(expression)).toBe(words);
    });
});
