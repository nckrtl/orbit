import { createStore } from "./core/store";

export type OrbitOptions = { tasksStatusUrl?: string };
type Availability = { state: "checking" | "available" | "unavailable"; reason: string };
export const orbitAvailability = createStore<Availability>({
    state: "unavailable",
    reason: "No Orbit annotation service configured.",
});
let serviceUrl = "";
let options: OrbitOptions = {};
let pending: Promise<Availability> | undefined;
let generation = 0;

export function configureOrbit(url: string | undefined, configuration: OrbitOptions = {}): void {
    serviceUrl = url ?? "";
    options = configuration;
    generation++;
    pending = undefined;
    orbitAvailability.setState({
        state: serviceUrl ? "checking" : "unavailable",
        reason: serviceUrl ? "Checking Orbit…" : "No Orbit annotation service configured.",
    });
}

/** Read-only checks; never enable extensions or send an annotation. */
export function checkOrbit(): Promise<Availability> {
    if (!serviceUrl) return Promise.resolve(orbitAvailability.getSnapshot());
    if (pending) return pending;
    const current = generation;
    const configuredUrl = serviceUrl;
    const tasksStatusUrl = options.tasksStatusUrl;
    orbitAvailability.setState({ state: "checking", reason: "Checking Orbit…" });
    pending = (async (): Promise<Availability> => {
        let result: Availability;
        try {
            const endpoint = new URL(configuredUrl, window.location.href);
            const tasksUrl = new URL(tasksStatusUrl ?? "/api/v1/tasks/status", endpoint);
            const init = {
                headers: { Accept: "application/json" },
                signal: AbortSignal.timeout(5000),
                cache: "no-store" as const,
            };
            const tasks = await fetch(tasksUrl, init);
            if (!tasks.ok) throw new Error(`Cannot check Orbit tasks (HTTP ${tasks.status}).`);
            const status = await tasks.json();
            if (typeof status.data?.enabled !== "boolean")
                throw new Error("Invalid Orbit tasks status response.");
            if (!status.data.enabled) throw new Error("Enable the tasks extension in Orbit.");
            const annotations = await fetch(endpoint, init);
            if (!annotations.ok)
                throw new Error(`Cannot access Orbit annotations (HTTP ${annotations.status}).`);
            if (!Array.isArray((await annotations.json()).data))
                throw new Error("Invalid Orbit annotation endpoint.");
            result = { state: "available", reason: "Orbit available" };
        } catch (error) {
            result = {
                state: "unavailable",
                reason:
                    error instanceof TypeError
                        ? "Cannot reach Orbit. Check the connection."
                        : error instanceof Error
                          ? error.message
                          : "Cannot check Orbit availability.",
            };
        }
        if (current === generation) orbitAvailability.setState(result);
        return result;
    })().finally(() => {
        if (current === generation) pending = undefined;
    });
    return pending;
}
