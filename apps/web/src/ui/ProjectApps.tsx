import { useMutation } from "@tanstack/react-query";
import { useState } from "react";
import { api } from "../api/client";
import { queryClient } from "../api/queryClient";
import type { Project } from "../api/types";
import { Frame, Note } from "./Frame";
import {
    APP_TYPES,
    APPS_LOCKED_NOTE,
    type AppRow,
    appDetail,
    appsFailure,
    appsPatchBody,
    emptyRow,
    rowsChanged,
    rowsComplete,
    rowsFromApps,
} from "./appRows";

const NAME_COLUMNS = { gridTemplateColumns: "minmax(12ch, 18ch) minmax(0, 1fr)" };
// Two fields a line on a phone; name, path, web root, type, and remove on one line from md up.
const EDIT_ROW =
    "grid grid-cols-2 gap-x-[1ch] md:grid-cols-[minmax(8ch,1fr)_minmax(8ch,1.4fr)_minmax(8ch,1fr)_minmax(14ch,16ch)_6ch]";
const INPUT = "w-full min-w-0 border-b bg-transparent outline-none focus:border-cyan";

/**
 * A Project's named apps. They are editable only while the Project has no Instances; the editor
 * sends the complete list, as `project:update --apps` does.
 */
export function ProjectApps({ project, editable }: { project: Project; editable: boolean }) {
    const apps = project.apps ?? [];

    return (
        <Frame
            title="Apps"
            className="w-full"
            testId="project-apps"
            topRight={editable ? undefined : "locked"}
        >
            {editable ? (
                <AppsEditor project={project} />
            ) : (
                <>
                    {apps.map((app) => (
                        <div
                            key={app.name}
                            className="row"
                            style={{
                                ...NAME_COLUMNS,
                                height: "auto",
                                alignItems: "start",
                                whiteSpace: "normal",
                            }}
                        >
                            <span>{app.name}</span>
                            <span className="selectable break-words">{appDetail(app)}</span>
                        </div>
                    ))}
                    <p className="whitespace-normal text-dim">{APPS_LOCKED_NOTE}</p>
                </>
            )}
        </Frame>
    );
}

function AppsEditor({ project }: { project: Project }) {
    const apps = project.apps ?? [];
    const [rows, setRows] = useState<AppRow[]>(() => rowsFromApps(apps));
    const save = useMutation({
        mutationFn: (body: ReturnType<typeof appsPatchBody>) =>
            api<Project>("PATCH", `/api/v1/projects/${project.id}`, body),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ["projects"] });
        },
    });
    const failure = appsFailure(save.error);
    const changed = rowsChanged(rows, apps);
    const complete = rowsComplete(rows);
    const edit = (index: number, next: Partial<AppRow> | null) => {
        setRows(
            next === null
                ? rows.filter((_, at) => at !== index)
                : rows.map((row, at) => (at === index ? { ...row, ...next } : row)),
        );
        save.reset();
    };
    const marked = (index: number, field: keyof AppRow) =>
        failure !== null && failure.row === index && (failure.field ?? field) === field
            ? "border-yellow"
            : "border-line";

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                if (!save.isPending && changed && complete) save.mutate(appsPatchBody(rows));
            }}
        >
            <fieldset disabled={save.isPending} className="min-w-0">
                <div className={`${EDIT_ROW} hidden text-dim md:grid`} aria-hidden="true">
                    <span>Name</span>
                    <span>Path</span>
                    <span>Web root</span>
                    <span>Type</span>
                    <span />
                </div>
                {rows.map((row, index) => (
                    // Rows have no identity until saved; the position is what the Gateway's error names too.
                    <div key={index} className={`${EDIT_ROW} gap-y-[2px] py-[2px]`}>
                        <input
                            aria-label={`App ${index + 1} name`}
                            placeholder="name"
                            className={`${INPUT} ${marked(index, "name")}`}
                            value={row.name}
                            onChange={(event) =>
                                edit(index, { name: event.target.value.toLowerCase() })
                            }
                        />
                        <input
                            aria-label={`App ${index + 1} path`}
                            placeholder="path, . for the root"
                            className={`${INPUT} ${marked(index, "path")}`}
                            value={row.path}
                            onChange={(event) => edit(index, { path: event.target.value })}
                        />
                        <input
                            aria-label={`App ${index + 1} web root`}
                            placeholder="none"
                            className={`${INPUT} ${marked(index, "webRoot")}`}
                            value={row.webRoot}
                            onChange={(event) => edit(index, { webRoot: event.target.value })}
                        />
                        <select
                            aria-label={`App ${index + 1} type`}
                            className={`${INPUT} bg-bg text-fg ${marked(index, "type")}`}
                            value={row.type}
                            onChange={(event) => edit(index, { type: event.target.value })}
                        >
                            {/* A type this page does not know yet stays selectable as it is. */}
                            {[...new Set([...APP_TYPES, row.type])].map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </select>
                        <button
                            type="button"
                            aria-label={`Remove app ${index + 1}`}
                            className="cursor-pointer justify-self-start text-dim hover:text-red disabled:cursor-default"
                            onClick={() => edit(index, null)}
                        >
                            remove
                        </button>
                    </div>
                ))}
                {rows.length === 0 && <Note>A Project needs at least one app.</Note>}
                <div className="mt-[2px] flex flex-wrap items-center gap-x-[2ch]">
                    <button
                        type="button"
                        className="cursor-pointer text-cyan disabled:text-dim"
                        onClick={() => {
                            setRows([...rows, emptyRow()]);
                            save.reset();
                        }}
                    >
                        Add app
                    </button>
                    {changed && (
                        <>
                            <button
                                type="submit"
                                disabled={save.isPending || !complete}
                                className="cursor-pointer text-cyan disabled:text-dim"
                            >
                                {save.isPending ? "Saving…" : "Save apps"}
                            </button>
                            <button
                                type="button"
                                className="cursor-pointer text-dim hover:text-fg"
                                onClick={() => {
                                    setRows(rowsFromApps(apps));
                                    save.reset();
                                }}
                            >
                                Reset
                            </button>
                        </>
                    )}
                </div>
            </fieldset>
            {failure !== null && (
                <p role="alert" className="whitespace-normal text-red">
                    {failure.code === null
                        ? failure.message
                        : `${failure.code}: ${failure.message}`}
                </p>
            )}
        </form>
    );
}
