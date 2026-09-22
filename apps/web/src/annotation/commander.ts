import type { Annotation } from "@/annotation/types";

export type CommanderConfig = {
    /** When false, submit is a no-op (local-only annotations). */
    enabled: boolean;
    /** Commander project id — same as toolbar.commander.project (default commander). */
    project: string;
    /** Same-origin path the Vite/dev adapter exposes; production can point at a Gateway proxy later. */
    endpoint: string;
};

export type CommanderOutcome =
    | { ok: true; taskId: number; dryRun?: false }
    | { ok: true; dryRun: true; warning: string }
    | { ok: false; error: string };

const DEFAULTS: CommanderConfig = {
    enabled: true,
    project: "commander",
    endpoint: "/__orbit/commander/one-shot",
};

let config: CommanderConfig = { ...DEFAULTS };

export function configureCommander(partial: Partial<CommanderConfig>): void {
    config = {
        ...config,
        ...partial,
        project: (partial.project ?? config.project).trim() || "commander",
    };
}

export function commanderConfig(): CommanderConfig {
    return config;
}

/**
 * Mirrors laravel-toolbar CommanderClient → SubmitOneShotTask:
 * create a kind=one-shot task with creation_key annotation:{id} and the full
 * annotation JSON as the description.
 */
export async function submitOneShotTask(annotation: Annotation): Promise<CommanderOutcome> {
    if (!config.enabled) {
        return { ok: false, error: "Commander one-shot disabled" };
    }

    const id = annotation.id?.trim();
    if (!id) {
        return { ok: false, error: "Annotation id is required" };
    }

    const title = (annotation.comment?.trim() || `Annotation ${id}`).slice(0, 255);

    try {
        const response = await fetch(config.endpoint, {
            method: "POST",
            headers: { Accept: "application/json", "Content-Type": "application/json" },
            body: JSON.stringify({
                project_id: config.project,
                title,
                description: annotation,
                kind: "one-shot",
                creation_key: `annotation:${id}`,
            }),
        });

        const payload: unknown = await response.json();
        const body =
            payload !== null && typeof payload === "object" && !Array.isArray(payload)
                ? (payload as Record<string, unknown>)
                : null;

        if (!response.ok) {
            return {
                ok: false,
                error:
                    typeof body?.error === "string"
                        ? body.error.slice(0, 512)
                        : `Commander returned HTTP ${response.status}.`,
            };
        }
        if (body && !("error" in body)) {
            if (body.dry_run === true) {
                return typeof body.warning === "string" && body.warning !== ""
                    ? { ok: true, dryRun: true, warning: body.warning.slice(0, 512) }
                    : { ok: false, error: "Commander returned an invalid dry-run response." };
            }

            const task = body.task as { id?: unknown } | null | undefined;
            if (typeof task?.id === "number" && Number.isSafeInteger(task.id) && task.id > 0) {
                return { ok: true, taskId: task.id };
            }
        }

        return { ok: false, error: "Commander did not return a valid created task." };
    } catch {
        return { ok: false, error: "Could not submit the annotation to Commander." };
    }
}
