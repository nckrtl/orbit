import { queryOptions } from "@tanstack/react-query";
import { get } from "../api/client";
import {
    parseDefinition,
    parseDefinitionList,
    type Definition,
    type ProxyModel,
} from "./definition";

/** Task definitions. Pass a Project id to ask the Gateway for that Project only. */
export const taskDefinitionsQuery = (projectId?: number) =>
    queryOptions({
        queryKey: ["task-definitions", projectId ?? "all"],
        queryFn: async (): Promise<Definition[]> => {
            const path =
                projectId === undefined
                    ? "/api/v1/task-definitions"
                    : `/api/v1/task-definitions?project_id=${projectId}`;
            return parseDefinitionList(await get<unknown>(path));
        },
        retry: false,
    });

export const taskDefinitionQuery = (projectId: string, name: string) =>
    queryOptions({
        queryKey: ["task-definitions", projectId, name],
        queryFn: async (): Promise<Definition | null> =>
            parseDefinition(
                await get<unknown>(
                    `/api/v1/projects/${encodeURIComponent(projectId)}/task-definitions/${encodeURIComponent(name)}`,
                ),
            ),
        retry: false,
    });

/** The models ProxyCli offers. The page asks only while the extension is on. */
export const proxyModelsQuery = queryOptions({
    queryKey: ["proxycli", "models"],
    queryFn: async (): Promise<ProxyModel[]> => {
        const rows = await get<unknown>("/api/v1/proxycli/models");
        if (!Array.isArray(rows)) return [];
        return rows.flatMap((row) => {
            if (row === null || typeof row !== "object") return [];
            const id = "id" in row && typeof row.id === "string" ? row.id : undefined;
            const provider =
                "provider" in row && typeof row.provider === "string" ? row.provider : undefined;
            return id !== undefined && provider !== undefined ? [{ id, provider }] : [];
        });
    },
    retry: false,
});
