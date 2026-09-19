import type { Annotation } from "@/annotation/types";

export type CommanderConfig = {
    /** When false, submit is a no-op (local-only annotations). */
    enabled: boolean;
    /** Commander project id — same as toolbar.commander.project (default commander). */
    project: string;
    /** Same-origin path the Vite/dev adapter exposes; production can point at a Gateway proxy later. */
    endpoint: string;
};

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
export async function submitOneShotTask(
    annotation: Annotation,
): Promise<{ ok: boolean; taskId?: number; error?: string }> {
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

        if (!response.ok) {
            const text = await response.text().catch(() => "");
            return { ok: false, error: text || `HTTP ${response.status}` };
        }

        const payload = (await response.json()) as { task?: { id?: number } };
        return { ok: true, taskId: payload.task?.id };
    } catch (error) {
        return { ok: false, error: error instanceof Error ? error.message : "submit failed" };
    }
}
