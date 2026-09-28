import { queryOptions } from "@tanstack/react-query";
import { get } from "../api/client";
import type { TaskTemplate, TemplateRun } from "./model";

export const templatesQuery = queryOptions({
    queryKey: ["task-templates"],
    queryFn: () => get<TaskTemplate[]>("/api/v1/task-templates"),
    retry: false,
});

export const templateQuery = (name: string) =>
    queryOptions({
        queryKey: ["task-templates", name],
        queryFn: () => get<TaskTemplate>(`/api/v1/task-templates/${encodeURIComponent(name)}`),
        retry: false,
    });

export const templateRunsQuery = (name: string) =>
    queryOptions({
        queryKey: ["task-templates", name, "runs"],
        queryFn: () =>
            get<TemplateRun[]>(`/api/v1/task-templates/${encodeURIComponent(name)}/runs`),
        retry: false,
    });
