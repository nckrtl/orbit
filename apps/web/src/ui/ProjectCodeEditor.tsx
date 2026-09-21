import { useMutation } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { api } from "../api/client";
import { queryClient } from "../api/queryClient";
import type { Project } from "../api/types";

export function ProjectCodeEditor({ project }: { project: Project }) {
    const [code, setCode] = useState(project.code ?? "");
    useEffect(() => setCode(project.code ?? ""), [project.id, project.code]);
    const save = useMutation({
        mutationFn: () => api<Project>("PATCH", `/api/v1/projects/${project.id}`, { code }),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ["projects"] });
            await queryClient.invalidateQueries({ queryKey: ["task-groups"] });
        },
    });
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                if (!save.isPending && /^[A-Z]{3}$/.test(code)) save.mutate();
            }}
        >
            <div
                className="row"
                style={{ gridTemplateColumns: "minmax(12ch, 18ch) minmax(0, 1fr)" }}
            >
                <label htmlFor={`project-code-${project.id}`} className="text-dim">
                    Code
                </label>
                <span className="flex items-center gap-[1ch]">
                    <input
                        id={`project-code-${project.id}`}
                        aria-label="Project code"
                        className="w-[5ch] border-b border-line bg-transparent uppercase outline-none focus:border-cyan"
                        value={code}
                        disabled={save.isPending}
                        maxLength={3}
                        pattern="[A-Z]{3}"
                        required
                        onChange={(event) => {
                            setCode(event.target.value.toUpperCase());
                            save.reset();
                        }}
                    />
                    {code !== project.code && (
                        <button
                            type="submit"
                            disabled={save.isPending || !/^[A-Z]{3}$/.test(code)}
                            className="cursor-pointer text-cyan disabled:text-dim"
                        >
                            {save.isPending ? "Saving…" : "Save"}
                        </button>
                    )}
                </span>
            </div>
            {save.error && (
                <p role="alert" className="text-red">
                    {save.error.message}
                </p>
            )}
        </form>
    );
}
