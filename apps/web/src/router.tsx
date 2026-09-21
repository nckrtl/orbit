import {
    createRootRoute,
    createRoute,
    createRouter,
    type RouterHistory,
} from "@tanstack/react-router";
import { TasksBoard, TaskDetail, SubtaskDetail } from "./pages/Tasks";
import { Dashboard } from "./pages/Dashboard";
import { NodeCreate } from "./pages/NodeCreate";
import { DeploymentPage, RecordPage } from "./pages/RecordPage";
import { SectionList } from "./pages/SectionList";
import { Shell } from "./ui/Shell";

// The URL carries what `orbit top` keeps in its page stack: the section, the open record, and the
// node and project filters. Back is the browser's own.
const rootRoute = createRootRoute({ component: Shell });

const text = (value: unknown): string | undefined =>
    typeof value === "string" && value !== "" ? value : undefined;

const routeTree = rootRoute.addChildren([
    createRoute({ getParentRoute: () => rootRoute, path: "/", component: Dashboard }),
    createRoute({ getParentRoute: () => rootRoute, path: "/tasks", component: TasksBoard }),
    createRoute({ getParentRoute: () => rootRoute, path: "/tasks/$id", component: TaskDetail }),
    createRoute({
        getParentRoute: () => rootRoute,
        path: "/tasks/$id/subtasks/$subtaskId",
        component: SubtaskDetail,
    }),
    createRoute({ getParentRoute: () => rootRoute, path: "/nodes/create", component: NodeCreate }),
    createRoute({
        getParentRoute: () => rootRoute,
        path: "/instances/$id/deployments/$deploymentId",
        component: DeploymentPage,
    }),
    createRoute({
        getParentRoute: () => rootRoute,
        path: "/$section",
        component: SectionList,
        validateSearch: (search: Record<string, unknown>): { node?: string; project?: string } => ({
            node: text(search.node),
            project: text(search.project),
        }),
    }),
    createRoute({ getParentRoute: () => rootRoute, path: "/$section/$id", component: RecordPage }),
]);

/** The app's router. Tests pass a memory history so each one starts at its own URL. */
export const createAppRouter = (history?: RouterHistory) => createRouter({ routeTree, history });

declare module "@tanstack/react-router" {
    interface Register {
        router: ReturnType<typeof createAppRouter>;
    }
}
