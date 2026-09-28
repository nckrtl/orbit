const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

const number = (field: string): number | null => (/^\d+$/.test(field) ? Number(field) : null);
const time = (hour: number, minute: number) =>
    `${String(hour).padStart(2, "0")}:${String(minute).padStart(2, "0")} UTC`;
const ordinal = (day: number) =>
    `${day}${day % 10 === 1 && day !== 11 ? "st" : day % 10 === 2 && day !== 12 ? "nd" : day % 10 === 3 && day !== 13 ? "rd" : "th"}`;

/**
 * A cron expression in words, for the common shapes: every N minutes, hourly, daily, on weekdays,
 * weekly, and monthly. Any other expression comes back as it is.
 */
export function describeCron(expression: string | null): string {
    if (expression === null) return "On demand";
    const fields = expression.trim().split(/\s+/);
    if (fields.length !== 5) return expression;
    const [minuteField, hourField, dayField, monthField, weekdayField] = fields as [
        string,
        string,
        string,
        string,
        string,
    ];
    if (monthField !== "*") return expression;

    const step = /^\*\/(\d+)$/.exec(minuteField);
    if (step && hourField === "*" && dayField === "*" && weekdayField === "*") {
        return `Every ${step[1]} minutes`;
    }

    const minute = number(minuteField);
    if (minute === null) return expression;

    if (hourField === "*" && dayField === "*" && weekdayField === "*") {
        return minute === 0 ? "Hourly" : `Hourly at :${String(minute).padStart(2, "0")}`;
    }

    const hour = number(hourField);
    if (hour === null) return expression;
    const at = time(hour, minute);

    if (dayField === "*" && weekdayField === "*") return `Daily at ${at}`;
    if (dayField === "*" && weekdayField === "1-5") return `Weekdays at ${at}`;

    const weekday = number(weekdayField);
    if (dayField === "*" && weekday !== null && weekday <= 7) {
        return `Weekly on ${DAYS[weekday % 7]} at ${at}`;
    }

    const day = number(dayField);
    if (day !== null && weekdayField === "*") return `Monthly on the ${ordinal(day)} at ${at}`;

    return expression;
}
