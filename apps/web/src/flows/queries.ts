import { queryOptions } from "@tanstack/react-query";
import { get } from "../api/client";
import type { ProxyModel, TaskTemplate, TemplateRun } from "./model";

export const templatesQuery = queryOptions({
    queryKey: ["task-templates"],
    queryFn: () => get<TaskTemplate[]>("/api/v1/task-templates"),
    retry: false,
});

const path = (project: string, name: string) =>
    `/api/v1/task-templates/${encodeURIComponent(project)}/${encodeURIComponent(name)}`;

export const templateQuery = (project: string, name: string) =>
    queryOptions({
        queryKey: ["task-templates", project, name],
        queryFn: () => get<TaskTemplate>(path(project, name)),
        retry: false,
    });

export const templateRunsQuery = (project: string, name: string) =>
    queryOptions({
        queryKey: ["task-templates", project, name, "runs"],
        queryFn: () => get<TemplateRun[]>(`${path(project, name)}/runs`),
        retry: false,
    });

/** The models ProxyCli offers. The Gateway has no such route yet; the demo Gateway serves it. */
export const proxyModelsQuery = queryOptions({
    queryKey: ["proxycli", "models"],
    queryFn: () => get<ProxyModel[]>("/api/v1/proxycli/models"),
    retry: false,
});
