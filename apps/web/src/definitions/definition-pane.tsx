import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "@tanstack/react-router";
import { extensionsQuery } from "../api/extensions";
import { lists } from "../api/queries";
import { type Column, Pane } from "../ui/Pane";
import { describeSchedule, modelNames, startsInLabel, type Definition } from "./definition";
import { taskDefinitionsQuery } from "./definition-queries";

/**
 * The task definitions as a table. With `projectId`, only that Project's definitions, and no
 * Project column. The list is read when the page opens and does not poll.
 */
export function DefinitionPane({
    projectId,
    order,
    className,
}: {
    projectId?: number;
    order: number;
    className?: string;
}) {
    const extensions = useQuery(extensionsQuery);
    const enabled = extensions.data?.tasks === true;
    const definitions = useQuery({ ...taskDefinitionsQuery(projectId), enabled });
    const projects = useQuery(lists.projects);
    const navigate = useNavigate();
    const projectName = (id: number) =>
        projects.data?.find((project) => project.id === id)?.name ?? `Project ${id}`;

    if (!enabled) return null;

    const columns: Column<Definition>[] = [
        { header: "Definition", width: 3, value: (row) => row.title },
        ...(projectId === undefined
            ? [
                  {
                      header: "Project",
                      width: 2,
                      value: (row: Definition) => projectName(row.project_id),
                  },
              ]
            : []),
        {
            header: "Schedule",
            width: 3,
            value: (row) => describeSchedule(row),
            hideOnMobile: true,
        },
        {
            header: "Subtasks",
            width: 1,
            fit: true,
            align: "right",
            value: (row) => String(row.subtasks.length),
        },
        {
            header: "Models",
            width: 1,
            fit: true,
            align: "right",
            value: (row) => String(modelNames(row).length),
            cell: (row) => <span title={modelNames(row).join(", ")}>{modelNames(row).length}</span>,
            hideOnMobile: true,
        },
        {
            header: "Starts in",
            width: 1,
            fit: true,
            value: (row) => startsInLabel(row.status),
            hideOnMobile: true,
        },
    ];

    return (
        <Pane
            name="task-definitions"
            order={order}
            title="Definitions"
            className={className}
            columns={columns}
            rows={definitions.data ?? []}
            rowId={(row) => `${row.project_id}/${row.name}`}
            onRowClick={(row) =>
                void navigate({
                    to: "/projects/$project/task-definitions/$name",
                    params: { project: String(row.project_id), name: row.name },
                })
            }
            empty={
                definitions.isPending
                    ? "Loading definitions…"
                    : definitions.error
                      ? "Could not load task definitions."
                      : "No task definitions."
            }
            testId="task-definitions"
        />
    );
}
