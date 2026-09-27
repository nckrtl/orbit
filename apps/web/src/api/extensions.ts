import { queryOptions } from "@tanstack/react-query";
import { get } from "./client";

export type ExtensionState = Record<string, boolean>;

export const extensionsQuery = queryOptions({
    queryKey: ["extensions"],
    queryFn: () => get<ExtensionState>("/api/v1/extensions"),
    staleTime: 30_000,
    refetchInterval: 30_000,
});
