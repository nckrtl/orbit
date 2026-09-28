import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "@tanstack/react-router";
import { lists } from "../api/queries";
import { Pane, type Column } from "../ui/Pane";
import { describeCron } from "./cron";
import { modelsOf, type TaskTemplate } from "./model";
import { templatesQuery } from "./queries";

/** The distinct models a template's tasks call. */
const modelNames = (template: TaskTemplate): string[] => [
    ...new Set(template.tasks.flatMap(modelsOf)),
];

/**
 * The task templates as a table. With `projectSlug`, only that Project's templates, and no Project
 * column.
 */
export function FlowsPane({
    projectSlug,
    order,
    className,
}: {
    projectSlug?: string;
    order: number;
    className?: string;
}) {
    const templates = useQuery(templatesQuery);
    const projects = useQuery(lists.projects);
    const navigate = useNavigate();
    const projectName = (slug: string) =>
        projects.data?.find((project) => project.slug === slug)?.name ?? slug;

    const columns: Column<TaskTemplate>[] = [
        { header: "Flow", width: 3, value: (row) => row.title },
        ...(projectSlug === undefined
            ? [{ header: "Project", width: 2, value: (row: TaskTemplate) => projectName(row.project_slug) }]
            : []),
        {
            header: "Schedule",
            width: 3,
            value: (row) => describeCron(row.cron),
            hideOnMobile: true,
        },
        { header: "Tasks", width: 1, fit: true, align: "right", value: (row) => String(row.tasks.length) },
        {
            header: "Models",
            width: 1,
            fit: true,
            align: "right",
            value: (row) => String(modelNames(row).length),
            cell: (row) => <span title={modelNames(row).join(", ")}>{modelNames(row).length}</span>,
            hideOnMobile: true,
        },
        { header: "Starts in", width: 1, fit: true, value: (row) => row.status, hideOnMobile: true },
    ];

    const rows = (templates.data ?? []).filter(
        (template) => projectSlug === undefined || template.project_slug === projectSlug,
    );

    return (
        <Pane
            name="flows"
            order={order}
            title="Flows"
            className={className}
            columns={columns}
            rows={rows}
            rowId={(row) => `${row.project_slug}/${row.name}`}
            onRowClick={(row) =>
                void navigate({
                    to: "/flows/$project/$name",
                    params: { project: row.project_slug, name: row.name },
                })
            }
            empty={templates.isPending ? "Loading flows…" : "No flows."}
            testId="flows-pane"
        />
    );
}
