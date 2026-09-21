import { useQuery } from "@tanstack/react-query";
import { instanceAnalyticsStatsQuery } from "../api/queries";
import type { AnalyticsPage, Instance } from "../api/types";
import { Frame, Note } from "../ui/Frame";
import { type Column, Pane } from "../ui/Pane";
import { Properties } from "../ui/Properties";

const pageColumns: Column<AnalyticsPage>[] = [
    { header: "Page", width: 70, value: (page) => page.path },
    { header: "Visitors", width: 12, fit: true, value: (page) => String(page.visitors) },
];

/**
 * Live visitors, period counts, and the top pages of the instance's tracked site. Hidden while
 * the fleet analytics role is down or the instance has no tracking host. A failed read is an
 * error, not zeros.
 */
export function AnalyticsPanel({ instance }: { instance: Instance }) {
    const stats = useQuery(instanceAnalyticsStatsQuery(instance.id)).data;

    if (stats === undefined || !stats.available) {
        return null;
    }

    if (stats.readable !== true) {
        return (
            <Frame title="Analytics" state="warn">
                <Note>{stats.error ?? "Stats cannot be read."}</Note>
            </Frame>
        );
    }

    return (
        <div className="grid max-h-[30vh] grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-x-[1ch]">
            <Properties
                title="Analytics"
                properties={[
                    { name: "Site", value: stats.site_domain },
                    { name: "Live visitors", value: stats.live_visitors },
                    { name: "Past 24h", value: stats.visitors?.past_24h },
                    { name: "Past 7d", value: stats.visitors?.past_7d },
                    { name: "Past 30d", value: stats.visitors?.past_30d },
                ]}
            />
            <Pane
                name="analytics-pages"
                order={4}
                title="Top pages"
                columns={pageColumns}
                rows={stats.pages ?? []}
                rowId={(page) => page.path}
                empty="No pages in the last 30 days."
            />
        </div>
    );
}
