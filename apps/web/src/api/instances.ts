import type { Instance, InstanceWire } from "./types";

/** Resolve the Gateway's legacy alias before a full row or partial update enters the cache. */
export function normalizeInstance(instance: InstanceWire): Instance;
export function normalizeInstance(instance: Partial<InstanceWire>): Partial<Instance>;
export function normalizeInstance({
    app,
    project,
    ...fields
}: Partial<InstanceWire>): Partial<Instance> {
    const identity = project ?? app;

    return identity === undefined ? fields : { ...fields, project: identity };
}
