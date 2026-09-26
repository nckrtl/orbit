import { QueryClient } from "@tanstack/react-query";
import { afterEach, describe, expect, it } from "vite-plus/test";
import {
    ACTIVITY_PAGE_LIMIT,
    activitiesQuery,
    activityQuery,
    olderActivityBeforeId,
    readActivitySearch,
} from "./activities";
import { setTransport } from "./client";

afterEach(() => {
    setTransport(null);
});

describe("activitiesQuery", () => {
    it("loads the newest page with the limit and no unused filters", async () => {
        const paths: string[] = [];
        setTransport(async (_method, path) => {
            paths.push(path);

            return { status: 200, payload: { data: [{ id: 50, command: "node:add" }] } };
        });

        const rows = await new QueryClient().fetchQuery(activitiesQuery({ command: "" }));

        expect(paths).toEqual(["/api/v1/activities?limit=25"]);
        expect(rows).toEqual([{ id: 50, command: "node:add" }]);
        expect(activitiesQuery().queryKey).toEqual(activitiesQuery({ command: "" }).queryKey);
    });

    it("loads an older page with before_id and the filters that are set", async () => {
        const paths: string[] = [];
        setTransport(async (_method, path) => {
            paths.push(path);

            return { status: 200, payload: { data: [{ id: 12, command: "node:add" }] } };
        });

        await new QueryClient().fetchQuery(
            activitiesQuery({
                before_id: 40,
                status: "failed",
                command: "node:add",
                caller_node_id: 3,
                target_node_id: 8,
            }),
        );

        expect(paths).toEqual([
            "/api/v1/activities?limit=25&before_id=40&status=failed&command=node%3Aadd&caller_node_id=3&target_node_id=8",
        ]);
        expect(activitiesQuery({ status: "failed", before_id: 40 }).queryKey).not.toEqual(
            activitiesQuery({ status: "failed" }).queryKey,
        );
        expect(activitiesQuery({ status: "running" }).queryKey).not.toEqual(
            activitiesQuery({ status: "failed" }).queryKey,
        );
    });
});

describe("activityQuery", () => {
    it("loads one row from activity:show, including the fields a notice omits", async () => {
        const paths: string[] = [];
        setTransport(async (_method, path) => {
            paths.push(path);

            return {
                status: 200,
                payload: {
                    data: { id: 40, command: "process:start", properties: { stdout: "bounded" } },
                },
            };
        });

        const row = await new QueryClient().fetchQuery(activityQuery("40"));

        expect(paths).toEqual(["/api/v1/activities/40"]);
        expect(row.properties).toEqual({ stdout: "bounded" });
        expect(activityQuery(40).queryKey).toEqual(["activities", "40"]);
        expect(activityQuery("40").queryKey).toEqual(activityQuery(40).queryKey);
    });

    it("surfaces a missing row as the Gateway error", async () => {
        setTransport(async () => ({
            status: 404,
            payload: { error: { code: "http.404", message: "Not found." } },
        }));

        await expect(new QueryClient().fetchQuery(activityQuery(9))).rejects.toMatchObject({
            status: 404,
            code: "http.404",
        });
    });
});

describe("readActivitySearch", () => {
    it("keeps a valid filter and drops an empty or invalid one", () => {
        expect(
            readActivitySearch({
                status: "failed",
                command: "node:add",
                caller_node_id: "3",
                target_node_id: 8,
                before_id: 40,
            }),
        ).toEqual({
            status: "failed",
            command: "node:add",
            caller_node_id: 3,
            target_node_id: 8,
            before_id: 40,
        });
        expect(
            readActivitySearch({
                status: "pending",
                command: "",
                caller_node_id: 0,
                target_node_id: "nope",
                before_id: -1,
            }),
        ).toEqual({});
        expect(readActivitySearch({ command: "x".repeat(256) }).command).toBeUndefined();
    });
});

describe("olderActivityBeforeId", () => {
    it("uses the smallest id on a full page, not the last row", () => {
        const rows = Array.from({ length: ACTIVITY_PAGE_LIMIT }, (_, index) => ({
            id: index === 3 ? 4 : 100 - index,
        }));

        expect(olderActivityBeforeId(rows)).toBe(4);
        expect(ACTIVITY_PAGE_LIMIT).toBe(25);
    });

    it("has no older page when the Gateway returns fewer rows than the limit", () => {
        expect(olderActivityBeforeId([])).toBeNull();
        expect(olderActivityBeforeId([{ id: 1 }, { id: 2 }])).toBeNull();
        expect(olderActivityBeforeId(Array.from({ length: 24 }, (_, id) => ({ id })))).toBeNull();
    });
});

describe("activity polling", () => {
    it("does not poll on its own clock", () => {
        expect(activitiesQuery({ before_id: 1 })).not.toHaveProperty("refetchInterval");
        expect(activityQuery(1)).not.toHaveProperty("refetchInterval");
    });
});
