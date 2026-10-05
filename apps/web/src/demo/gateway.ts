import type { Method, Transport } from "../api/client";
import type {
    Database,
    FirewallRule,
    Instance,
    ManagedFirewallRule,
    Node,
    Process,
    QuotaAccount,
    QuotaProvider,
    Schedule,
    Tool,
    ToolInventory,
    ToolInventoryPackage,
} from "../api/types";
import type { Activity } from "../api/activities";
import type { TaskGroup } from "../api/tasks";
import type { Definition } from "../definitions/definition";
import { createDemoDocuments } from "./documents";

type Fixture = { route: string; status: number; body: { data: unknown } };
type Answer = { status: number; payload: unknown };

// The fleet: hand-written fixtures that refer to each other, validated by bin/api-fixtures.
const fleet = import.meta.glob<Fixture>("../../fixtures/fleet/*.json", {
    eager: true,
    import: "default",
});
// Recorded Gateway responses, for the answers one record is enough for.
const recorded = import.meta.glob<Fixture>(
    [
        "../../../../packages/php-sdk/fixtures/nodes/node-add/*.json",
        "../../../../packages/php-sdk/fixtures/realtime/realtime-show/unconfigured.json",
        "../../../../packages/php-sdk/fixtures/instances/instance-deployment-show/default.json",
        "../../../../packages/php-sdk/fixtures/database-connections/database-user-list/default.json",
    ],
    { eager: true, import: "default" },
);

const recordedFixture = (name: string): Fixture => {
    const found = Object.entries(recorded).find(([path]) => path.endsWith(`${name}.json`));

    if (found === undefined) {
        throw new Error(`Recorded fixture ${name} is missing.`);
    }

    return found[1];
};

const META = { request_id: "0198e15c-bf97-7c23-8f1f-61b8fe67a844" };
const ok = (data: unknown, status = 200): Answer => ({ status, payload: { data, meta: META } });
const failure = (status: number, code: string, message: string): Answer => ({
    status,
    payload: { error: { code, message, request_id: META.request_id } },
});
const toolFailure = (
    status: number,
    code: string,
    message: string,
    details: Record<string, unknown>,
): Answer => ({
    status,
    payload: { error: { code, message, details, request_id: META.request_id } },
});
const notFound = (what: string): Answer =>
    failure(404, "resource.not_found", `${what} was not found.`);

/** The Activity list the page asks for: newest id first, with the same filters `activity:list` accepts. */
function activityPage(rows: readonly Activity[], query: string): Activity[] {
    const params = new URLSearchParams(query);
    const before = positiveParam(params.get("before_id"));
    const caller = positiveParam(params.get("caller_node_id"));
    const target = positiveParam(params.get("target_node_id"));
    const status = params.get("status");
    const command = params.get("command");
    const limitParam = params.get("limit");
    const limit = limitParam !== null && /^[1-9]\d*$/.test(limitParam) ? Number(limitParam) : 25;

    return rows
        .filter((row) => before === undefined || row.id < before)
        .filter((row) => status === null || status === "" || row.status === status)
        .filter((row) => command === null || command === "" || row.command === command)
        .filter((row) => caller === undefined || row.caller_node_id === caller)
        .filter((row) => target === undefined || row.target_node_id === target)
        .sort((left, right) => right.id - left.id)
        .slice(0, limit);
}

function positiveParam(value: string | null): number | undefined {
    if (value === null || !/^[1-9]\d*$/.test(value)) {
        return undefined;
    }

    const parsed = Number(value);

    return Number.isSafeInteger(parsed) ? parsed : undefined;
}

export type DemoRequest = { method: Method; path: string; body: unknown };

/**
 * A Gateway that lives in the page. It answers the routes the web app calls from the fixture
 * fleet, and it applies the record actions to its own copy of that fleet, so a stopped process
 * stays stopped. Demo mode (`bun run demo`) and the tests both run against it.
 */
export function createDemoGateway() {
    const requests: DemoRequest[] = [];
    // A fixture file named `operation.key.json` answers the route whose last parameter is `key`.
    const byRoute = new Map<string, unknown>();

    for (const [path, fixture] of Object.entries(fleet)) {
        const [, key] = (path.split("/").pop() ?? "").replace(/\.json$/, "").split(".");
        byRoute.set(`${fixture.route}#${key ?? ""}`, structuredClone(fixture.body.data));
    }

    const list = <T>(route: string, key = ""): T[] =>
        (byRoute.get(`${route}#${key}`) as T[] | undefined) ?? [];
    const nodes = list<Node>("GET /api/v1/nodes");
    const processes = list<Process>("GET /api/v1/processes");
    const schedules = list<Schedule>("GET /api/v1/schedules");
    const databases = list<Database>("GET /api/v1/database-connections");
    const instances = list<Instance>("GET /api/v1/instances");
    const taskGroups = list<TaskGroup>("GET /api/v1/task-groups");
    const taskDefinitions = list<Definition>("GET /api/v1/task-definitions");
    const proxyModels = list<{ id: string; provider: string }>("GET /api/v1/proxycli/models");
    const activities = list<Activity>("GET /api/v1/activities");
    // The instances that publish a tracking host; none does until a test or a visitor enables one.
    const trackedInstances = new Set<string>();
    const quotaAccounts: QuotaAccount[] = [
        {
            id: "plus.json",
            provider: "codex",
            label: "plus",
            disabled: false,
            status: "ok",
            windows: [
                {
                    label: "7d",
                    used_percent: 40,
                    remaining_percent: 60,
                    resets_at: "2026-09-27T00:00:00Z",
                },
                { label: "5h", used_percent: 10, remaining_percent: 90, resets_at: null },
            ],
            error: null,
        },
    ];
    const quotaProviders = (): QuotaProvider[] => [
        {
            provider: "codex",
            windows: quotaAccounts[0]?.windows ?? [],
            accounts: quotaAccounts,
        },
    ];
    let proxycliExtensionEnabled = false;
    let proxycliConfigured = false;
    let tasksEnabled = true;
    const rules = (node: string) =>
        list<FirewallRule>("GET /api/v1/nodes/{node}/firewall-rules", node);
    const tools = list<Tool>("GET /api/v1/tools");
    const nodeById = (id: string): Node | undefined =>
        nodes.find((candidate) => String(candidate.id) === id);
    const hasActiveRole = (id: string): boolean => (nodeById(id)?.roles?.length ?? 0) > 0;
    const wireguardMembers = (id: string) => ({
        name: "orbit:wireguard-members",
        role: null,
        action: "allow",
        source: "any",
        destination: nodeById(id)?.wireguard_ip ?? "10.44.0.2",
        port: "any",
        protocol: "any",
        interface: "orbit",
    });
    const publicSshRecovery = {
        name: "orbit:public-ssh-recovery",
        role: null,
        action: "allow",
        source: "any",
        destination: "any",
        port: "22",
        protocol: "tcp",
        interface: null,
    };
    const gatewayHttps = {
        name: "orbit:gateway-https",
        role: "gateway",
        action: "allow",
        source: "any",
        destination: "any",
        port: "443",
        protocol: "tcp",
        interface: "orbit",
    };
    const managedRules = (id: string) => {
        const rows: ManagedFirewallRule[] = hasActiveRole(id)
            ? [wireguardMembers(id)]
            : [publicSshRecovery, wireguardMembers(id)];

        if (nodeById(id)?.roles?.includes("gateway")) {
            rows.push(gatewayHttps);
        }

        return rows;
    };
    const liveRules = (id: string) => {
        const managed = managedRules(id);
        const operator = rules(id);

        return {
            backend_status: "active",
            live: [
                ...operator.map((rule) => ({
                    name: rule.name,
                    comment: `orbit:node:${id}:firewall:${rule.name}`,
                    action: rule.action,
                    source: rule.source,
                    destination: "any",
                    port: rule.port,
                    protocol: rule.protocol,
                    interface: null,
                    family: "v4",
                    match: "exact",
                })),
                ...managed.map((rule) => ({
                    name: rule.name,
                    comment: rule.name,
                    action: rule.action,
                    source: rule.source,
                    destination: rule.destination,
                    port: rule.port,
                    protocol: rule.protocol,
                    interface: rule.interface,
                    family: "v4",
                    match: "exact",
                })),
            ],
            missing: [],
        };
    };

    const inventoryFor = (id: string): ToolInventory | undefined => {
        const found = byRoute.get(`GET /api/v1/tool-inventory#${id}`);

        return found === undefined ? undefined : (found as ToolInventory);
    };
    const packageIn = (
        inventory: ToolInventory,
        manager: string,
        packageName: string,
    ): ToolInventoryPackage | undefined => {
        for (const entry of inventory.managers) {
            if (entry.scan_state !== "complete") {
                continue;
            }

            const found = entry.packages.find(
                (pkg) => pkg.manager === manager && pkg.package === packageName,
            );

            if (found !== undefined) {
                return found;
            }
        }

        return undefined;
    };
    const constraintValid = (value: string): boolean =>
        value === "*" || /^(?:\^|~|>=|<=|>|<)?\d+(?:\.\d+){0,2}$/.test(value);
    const constraintAllows = (
        constraint: string,
        version: string | null,
    ): "ok" | "unparseable" | "violated" => {
        if (version === null || !/^\d+\.\d+\.\d+/.test(version)) {
            return "unparseable";
        }

        if (constraint === "*") {
            return "ok";
        }

        const exact = constraint.match(/^(\d+)\.(\d+)\.(\d+)$/);

        if (exact !== null) {
            return version.startsWith(`${exact[1]}.${exact[2]}.${exact[3]}`) ? "ok" : "violated";
        }

        const caret = constraint.match(/^\^(\d+)/);

        if (caret !== null) {
            return Number(version.split(".")[0]) === Number(caret[1]) ? "ok" : "violated";
        }

        return "ok";
    };
    const scanInventory = (query: string): Answer => {
        const params = new URLSearchParams(query);

        if ([...params.keys()].some((key) => key !== "node_id")) {
            return failure(422, "validation.failed", "The query is not the node id.");
        }

        const nodeId = params.get("node_id");

        if (nodeId === null || !/^[1-9]\d*$/.test(nodeId)) {
            return failure(422, "validation.failed", "The node id must be a positive integer.");
        }

        const node = nodeById(nodeId);

        if (node === undefined) {
            return notFound("Node");
        }

        if (node.status !== "active") {
            return toolFailure(
                409,
                "tool.node_inactive",
                "Tools can be scanned only on an active node.",
                { step: "scan", outcome: "manager_failed" },
            );
        }

        const inventory = inventoryFor(nodeId);

        return inventory === undefined ? notFound("Tool inventory") : ok(inventory);
    };
    const adoptTool = (body: Record<string, unknown>): Answer => {
        const allowed = new Set(["node_id", "manager", "package", "version_constraint"]);

        if (Object.keys(body).some((key) => !allowed.has(key))) {
            return failure(422, "validation.failed", "The body has an unknown field.");
        }

        const nodeId = body.node_id;
        const manager = body.manager;
        const packageName = body.package;
        const constraint = body.version_constraint;

        if (typeof nodeId !== "number" || !Number.isInteger(nodeId) || nodeId < 1) {
            return failure(422, "validation.failed", "The node id must be an integer.");
        }

        if (typeof manager !== "string" || typeof packageName !== "string") {
            return failure(422, "validation.failed", "The manager and package must be strings.");
        }

        if (constraint !== undefined && constraint !== null && typeof constraint !== "string") {
            return failure(422, "validation.failed", "The version constraint must be a string.");
        }

        const storedConstraint = typeof constraint === "string" ? constraint : null;

        if (storedConstraint !== null && !constraintValid(storedConstraint)) {
            return toolFailure(
                422,
                "tool.constraint_invalid",
                "The version constraint is invalid.",
                {
                    step: "adopt",
                    outcome: "constraint_invalid",
                },
            );
        }

        const node = nodeById(String(nodeId));

        if (node === undefined) {
            return notFound("Node");
        }

        if (node.status !== "active") {
            return toolFailure(
                409,
                "tool.node_inactive",
                "Tools can be adopted only on an active node.",
                {
                    step: "adopt",
                    outcome: "manager_failed",
                },
            );
        }

        const inventory = inventoryFor(String(nodeId));
        const found =
            inventory === undefined ? undefined : packageIn(inventory, manager, packageName);

        if (found === undefined || packageName === "ghost") {
            return toolFailure(409, "tool.package_absent", "The package is not installed.", {
                step: "adopt",
                outcome: "manager_failed",
            });
        }

        if (found.adoption !== "supported") {
            return toolFailure(409, "tool.adoption_unsupported", "The package cannot be adopted.", {
                step: "adopt",
                outcome: "manager_failed",
                adoption_block: found.adoption_block,
            });
        }

        const versionCheck =
            storedConstraint === null
                ? "ok"
                : constraintAllows(storedConstraint, found.installed_version);

        if (versionCheck === "unparseable") {
            return toolFailure(
                409,
                "tool.installed_version_unparseable",
                "The installed version cannot be verified.",
                { step: "adopt", outcome: "manager_failed" },
            );
        }

        if (versionCheck === "violated") {
            return toolFailure(
                409,
                "tool.installed_version_constraint_violated",
                "The installed version does not satisfy the constraint.",
                { step: "adopt", outcome: "manager_failed" },
            );
        }

        const existing = tools.find(
            (candidate) =>
                candidate.node_id === nodeId &&
                candidate.manager === manager &&
                candidate.package === packageName,
        );

        if (existing !== undefined) {
            if ((existing.version_constraint ?? null) !== storedConstraint) {
                return toolFailure(
                    409,
                    "tool.constraint_conflict",
                    "The Tool has another constraint.",
                    {
                        step: "adopt",
                        outcome: "manager_failed",
                        id: existing.id,
                    },
                );
            }

            if (existing.status === "installed") {
                return ok({ ...existing, outcome: "unchanged" });
            }

            existing.status = "installed";
            existing.failed_operation = null;
            existing.error_code = null;
            existing.installed_version = found.installed_version;
            existing.outcome = "applied";

            return ok(existing);
        }

        const created: Tool = {
            id: Math.max(...tools.map((candidate) => candidate.id)) + 1,
            node_id: nodeId,
            manager,
            package: packageName,
            version_constraint: storedConstraint,
            status: "installed",
            installed_version: found.installed_version,
            failed_operation: null,
            error_code: null,
            outcome: "applied",
        };
        tools.push(created);
        found.registered = true;
        found.tool_id = created.id;

        return ok(created, 201);
    };
    const ownedTool = (id: string): Tool | Answer => {
        const tool = tools.find((candidate) => String(candidate.id) === id);

        if (tool === undefined) {
            return notFound("Tool");
        }

        const node = nodeById(String(tool.node_id));

        if (node === undefined || node.status !== "active") {
            return toolFailure(
                409,
                "tool.node_inactive",
                "Tools can be changed only on an active node.",
                { step: "update", outcome: "manager_failed", id: tool.id },
            );
        }

        return tool;
    };
    const updateTool = (id: string): Answer => {
        const tool = ownedTool(id);

        if (!("package" in tool)) {
            return tool;
        }

        const inventory = inventoryFor(String(tool.node_id));
        const found =
            inventory === undefined ? undefined : packageIn(inventory, tool.manager, tool.package);
        const observed = found?.installed_version ?? null;

        if (
            tool.version_constraint !== null &&
            observed !== null &&
            constraintAllows(tool.version_constraint, observed) === "violated"
        ) {
            return ok({ ...tool, outcome: "blocked_by_constraint" });
        }

        if (observed !== null && observed !== tool.installed_version) {
            tool.installed_version = observed;
            tool.status = "installed";
            tool.failed_operation = null;
            tool.error_code = null;
            tool.outcome = "applied";

            return ok(tool);
        }

        return ok({ ...tool, outcome: "unchanged" });
    };
    const removeTool = (id: string): Answer => {
        const tool = ownedTool(id);

        if (!("package" in tool)) {
            const refused = tool;

            if (
                typeof refused.payload === "object" &&
                refused.payload !== null &&
                "error" in refused.payload
            ) {
                const error = (refused.payload as { error: { details?: { step?: string } } }).error;

                if (error.details !== undefined) {
                    error.details.step = "remove";
                }
            }

            return refused;
        }

        const index = tools.findIndex((candidate) => candidate.id === tool.id);
        const removed = tools.splice(index, 1)[0];

        if (removed === undefined) {
            return notFound("Tool");
        }

        const inventory = inventoryFor(String(removed.node_id));
        const manager = inventory?.managers.find((entry) => entry.manager === removed.manager);

        if (manager !== undefined) {
            manager.packages = manager.packages.filter((pkg) => pkg.package !== removed.package);
        }

        return ok({ ...removed, outcome: "applied" });
    };

    const routes: [Method, RegExp, (params: string[], body: Record<string, unknown>) => Answer][] =
        [
            [
                "GET",
                /^\/api\/v1\/extensions$/,
                () => ok({ tasks: tasksEnabled, proxycli: proxycliExtensionEnabled }),
            ],
            [
                "GET",
                /^\/api\/v1\/proxycli$/,
                () =>
                    ok({
                        enabled: proxycliConfigured,
                        hostname: "collector.cli-proxy-api.orbit",
                        node_id: proxycliConfigured ? 2 : null,
                        cache_connection: proxycliConfigured ? "valkey" : null,
                        collected_at: proxycliConfigured ? "2026-09-20T12:00:00Z" : null,
                    }),
            ],
            ["GET", /^\/api\/v1\/tasks\/status$/, () => ok({ enabled: tasksEnabled })],
            [
                "GET",
                /^\/api\/v1\/proxycli\/providers$/,
                () =>
                    proxycliExtensionEnabled && proxycliConfigured
                        ? ok(quotaProviders())
                        : failure(
                              409,
                              "proxycli.disabled",
                              "The proxycli extension is disabled or unconfigured.",
                          ),
            ],
            [
                "GET",
                /^\/api\/v1\/proxycli\/providers\/([^/]+)$/,
                ([provider = ""]) => {
                    if (!proxycliExtensionEnabled || !proxycliConfigured) {
                        return failure(
                            409,
                            "proxycli.disabled",
                            "The proxycli extension is disabled.",
                        );
                    }

                    const pool = quotaProviders().find((row) => row.provider === provider);

                    return pool === undefined ? notFound("Provider") : ok(pool);
                },
            ],
            [
                "PATCH",
                /^\/api\/v1\/proxycli\/accounts\/([^/]+)$/,
                ([account = ""], body) => {
                    if (!proxycliExtensionEnabled || !proxycliConfigured) {
                        return failure(
                            409,
                            "proxycli.disabled",
                            "The proxycli extension is disabled.",
                        );
                    }

                    const row = quotaAccounts.find(
                        (candidate) => candidate.id === decodeURIComponent(account),
                    );

                    if (row === undefined) {
                        return notFound("Account");
                    }

                    row.disabled = body.disabled === true;

                    return ok(row);
                },
            ],
            ["GET", /^\/api\/v1\/realtime$/, () => ok(recordedFixture("unconfigured").body.data)],
            ["GET", /^\/api\/v1\/nodes$/, () => ok(nodes)],
            ["GET", /^\/api\/v1\/projects$/, () => ok(list("GET /api/v1/projects"))],
            ["GET", /^\/api\/v1\/apps$/, () => ok(list("GET /api/v1/projects"))],
            ["GET", /^\/api\/v1\/instances$/, () => ok(list("GET /api/v1/instances"))],
            ["GET", /^\/api\/v1\/processes$/, () => ok(processes)],
            ["GET", /^\/api\/v1\/schedules$/, () => ok(schedules)],
            ["GET", /^\/api\/v1\/database-connections$/, () => ok(databases)],
            [
                "GET",
                /^\/api\/v1\/task-definitions(?:\?(.*))?$/,
                ([query = ""]) => {
                    if (!tasksEnabled) {
                        return failure(
                            409,
                            "extension.disabled",
                            "The tasks extension is disabled.",
                        );
                    }
                    const projectId = new URLSearchParams(query).get("project_id");
                    const rows =
                        projectId === null
                            ? taskDefinitions
                            : taskDefinitions.filter((row) => String(row.project_id) === projectId);

                    return ok(rows);
                },
            ],
            [
                "GET",
                /^\/api\/v1\/projects\/(\d+)\/task-definitions\/([^/]+)$/,
                ([project = "", name = ""]) => {
                    if (!tasksEnabled) {
                        return failure(
                            409,
                            "extension.disabled",
                            "The tasks extension is disabled.",
                        );
                    }
                    const found = taskDefinitions.find(
                        (row) =>
                            String(row.project_id) === project &&
                            row.name === decodeURIComponent(name),
                    );

                    return found === undefined ? notFound("Task definition") : ok(found);
                },
            ],
            [
                "GET",
                /^\/api\/v1\/proxycli\/models$/,
                () =>
                    proxycliExtensionEnabled && proxycliConfigured
                        ? ok(proxyModels)
                        : failure(
                              409,
                              "proxycli.disabled",
                              "The proxycli extension is disabled or unconfigured.",
                          ),
            ],
            ["GET", /^\/api\/v1\/task-groups$/, () => ok(taskGroups)],
            [
                "GET",
                /^\/api\/v1\/task-groups\/(\d+)$/,
                ([group = ""]) => {
                    const found = taskGroups.find((candidate) => String(candidate.id) === group);

                    return found === undefined ? notFound("Task group") : ok(found);
                },
            ],
            [
                "GET",
                /^\/api\/v1\/activities\/(\d+)$/,
                ([id = ""]) => {
                    const found = activities.find((candidate) => String(candidate.id) === id);

                    return found === undefined ? notFound("Activity") : ok(found);
                },
            ],
            [
                "GET",
                /^\/api\/v1\/activities(?:\?(.*))?$/,
                ([query = ""]) => ok(activityPage(activities, query)),
            ],
            [
                "GET",
                /^\/api\/v1\/task-groups\/(\d+)\/agents$/,
                ([group = ""]) => ok(list("GET /api/v1/task-groups/{group}/agents", group)),
            ],
            [
                "GET",
                /^\/api\/v1\/task-groups\/(\d+)\/tasks\/(\d+)\/comments$/,
                ([, task = ""]) =>
                    ok(list("GET /api/v1/task-groups/{group}/tasks/{task}/comments", task)),
            ],
            [
                "GET",
                /^\/api\/v1\/firewall-rules$/,
                () =>
                    ok(
                        [...nodes]
                            .sort((a, b) => a.id - b.id)
                            .flatMap((node) => rules(String(node.id))),
                    ),
            ],
            ["GET", /^\/api\/v1\/nodes\/(\d+)\/firewall-rules$/, ([node = ""]) => ok(rules(node))],
            [
                "GET",
                /^\/api\/v1\/nodes\/(\d+)\/managed-firewall-rules$/,
                ([node = ""]) => ok(managedRules(node)),
            ],
            [
                "GET",
                /^\/api\/v1\/nodes\/(\d+)\/live-firewall-rules$/,
                ([node = ""]) => ok(liveRules(node)),
            ],
            [
                "GET",
                /^\/api\/v1\/tools(?:\?(.*))?$/,
                ([query = ""]) => {
                    const nodeId = new URLSearchParams(query).get("node_id");

                    if (nodeId === null || !/^[1-9]\d*$/.test(nodeId)) {
                        return failure(
                            422,
                            "validation.failed",
                            "The node id must be a positive integer.",
                        );
                    }

                    return ok(tools.filter((tool) => String(tool.node_id) === nodeId));
                },
            ],
            [
                "GET",
                /^\/api\/v1\/tool-inventory(?:\?(.*))?$/,
                ([query = ""]) => scanInventory(query),
            ],
            ["POST", /^\/api\/v1\/tools\/adopt$/, (_, body) => adoptTool(body)],
            ["POST", /^\/api\/v1\/tools\/(\d+)\/update$/, ([id = ""]) => updateTool(id)],
            ["DELETE", /^\/api\/v1\/tools\/(\d+)$/, ([id = ""]) => removeTool(id)],
            [
                "GET",
                /^\/api\/v1\/metrics\/credentials$/,
                () => ok(byRoute.get("GET /api/v1/metrics/credentials#")),
            ],
            [
                "GET",
                /^\/api\/v1\/instances\/(\d+)\/deployments$/,
                ([instance = ""]) =>
                    ok(list("GET /api/v1/instances/{instance}/deployments", instance)),
            ],
            [
                "GET",
                /^\/api\/v1\/deployments\/(\d+)$/,
                () => ok(recordedFixture("instance-deployment-show/default").body.data),
            ],
            [
                "GET",
                /^\/api\/v1\/database-connections\/([^/]+)\/tables$/,
                ([slug = ""]) =>
                    ok(
                        byRoute.get(
                            `GET /api/v1/database-connections/{database_connection}/tables#${slug}`,
                        ) ?? { slug, driver: "sqlite", tables: [] },
                    ),
            ],
            [
                "GET",
                /^\/api\/v1\/database-connections\/([^/]+)\/users$/,
                ([slug = ""]) =>
                    ok(
                        slug === "charlie-shop"
                            ? recordedFixture("database-user-list/default").body.data
                            : [],
                    ),
            ],
            [
                "GET",
                /^\/api\/v1\/instances\/(\d+)\/analytics\/stats$/,
                ([id = ""]) => {
                    if (!trackedInstances.has(id)) {
                        return ok({ available: false });
                    }

                    const domain =
                        instances.find((candidate) => String(candidate.id) === id)?.domain ?? null;

                    // Instance 2 is the fixture instance without Horizon; use it for the failed read.
                    if (id === "2") {
                        return ok({
                            available: true,
                            readable: false,
                            driver: "plausible_ce",
                            site_domain: domain,
                            error_code: "analytics.stats_key_missing",
                            error: "No Plausible Stats API key is stored. Create one in Plausible and store it with analytics:credentials.",
                        });
                    }

                    return ok({
                        available: true,
                        readable: true,
                        driver: "plausible_ce",
                        site_domain: domain,
                        live_visitors: 2,
                        visitors: { past_24h: 18, past_7d: 91, past_30d: 340 },
                        pages: [
                            { path: "/", visitors: 120 },
                            { path: "/pricing", visitors: 40 },
                            { path: "/docs", visitors: 18 },
                        ],
                    });
                },
            ],
            ...(["GET", "POST", "DELETE"] as const).map(
                (method): [Method, RegExp, (match: string[]) => Answer] => [
                    method,
                    /^\/api\/v1\/instances\/(\d+)\/analytics$/,
                    ([id = ""]) => {
                        if (method !== "GET") {
                            trackedInstances[method === "POST" ? "add" : "delete"](id);
                        }

                        const domain =
                            instances.find((candidate) => String(candidate.id) === id)?.domain ??
                            null;
                        const host = `analytics.${domain}`;
                        const enabled = trackedInstances.has(id);

                        return ok({
                            instance_id: Number(id),
                            enabled,
                            domain,
                            dashboard_url: "https://analytics.orbit",
                            hosts: enabled
                                ? [
                                      {
                                          host,
                                          route_id: 900 + Number(id),
                                          status: "active",
                                          publication: "public",
                                          failed_step: null,
                                          error_code: null,
                                          script_url: `https://${host}/js/script.js`,
                                          event_url: `https://${host}/api/event`,
                                          dns: { type: "CNAME", name: host, value: domain },
                                      },
                                  ]
                                : [],
                            snippet: enabled
                                ? `<script defer data-domain="${domain}" src="https://${host}/js/script.js"></script>`
                                : null,
                        });
                    },
                ],
            ),
            [
                "GET",
                /^\/api\/v1\/instances\/(\d+)\/queue\?state=(pending|completed|failed)$/,
                ([id = "", state = "pending"]) => {
                    // Only charlie-shop/dev runs Horizon in the fixture fleet.
                    if (id !== "1") {
                        return ok({ available: false, state });
                    }

                    const dashboard = "https://charlie-shop.test/horizon";
                    const job = (jobId: string, name: string, failed: boolean) => ({
                        id: jobId,
                        name,
                        queue: "default",
                        status: failed ? "failed" : state,
                        pushed_at: "2026-09-19T10:00:00Z",
                        completed_at: state === "completed" ? "2026-09-19T10:00:02Z" : null,
                        failed_at: failed ? "2026-09-19T10:00:02Z" : null,
                        exception: failed
                            ? "RuntimeException: The mail server refused the message."
                            : null,
                        url: `${dashboard}${failed ? "/failed/" : `/jobs/${state}/`}${jobId}`,
                    });

                    return ok({
                        available: true,
                        process_id: 1,
                        status: "running",
                        jobs_per_minute: 12,
                        recent_jobs: 40,
                        recently_failed_jobs: 1,
                        processes: 3,
                        totals: { pending: 0, completed: 2, failed: 1 },
                        queues: [{ name: "default", length: 0, wait_seconds: 0, processes: 3 }],
                        dashboard_url: dashboard,
                        state,
                        jobs:
                            state === "completed"
                                ? [
                                      job("a1", "App\\Jobs\\SendInvoice", false),
                                      job("a2", "App\\Jobs\\SyncStock", false),
                                  ]
                                : state === "failed"
                                  ? [job("f1", "App\\Jobs\\SendReceipt", true)]
                                  : [],
                    });
                },
            ],
            [
                "GET",
                /^\/api\/v1\/instances\/(\d+)\/logs(?:\?.*)?$/,
                ([id = ""]) =>
                    ok({
                        id: Number(id),
                        name: "main",
                        lines: 2,
                        logs: "[2026-09-19 10:00:00] local.INFO: Order 1042 paid.\n[2026-09-19 10:00:04] local.ERROR: Mail to [REDACTED] failed.\n",
                    }),
            ],
            [
                "GET",
                /^\/api\/v1\/processes\/(\d+)\/logs$/,
                ([id = ""]) => {
                    const process = processes.find((candidate) => String(candidate.id) === id);

                    return process === undefined
                        ? notFound("Process")
                        : ok({
                              id: process.id,
                              name: process.name,
                              lines: 3,
                              logs: `Starting ${process.name}.\n${process.name} is ready.\nProcessed 12 jobs.\n`,
                          });
                },
            ],
            [
                "GET",
                /^\/api\/v1\/schedules\/([^/]+)\/logs$/,
                ([id = ""]) =>
                    ok({
                        output:
                            id === "backup" ? "Backup started.\nBackup finished in 12 s.\n" : "",
                        truncated: false,
                    }),
            ],
            [
                "POST",
                /^\/api\/v1\/processes\/(\d+)\/(start|stop|restart)$/,
                ([id = "", verb = ""]) => {
                    const process = processes.find((candidate) => String(candidate.id) === id);

                    if (process === undefined) {
                        return notFound("Process");
                    }

                    const [active, inactive] =
                        process.runtime === "docker"
                            ? ["running", "exited"]
                            : ["active", "inactive"];
                    process.desired_state = verb === "stop" ? "stopped" : "running";
                    process.runtime_status = verb === "stop" ? inactive : active;

                    return ok(process);
                },
            ],
            [
                "POST",
                /^\/api\/v1\/schedules\/([^/]+)\/(run|activate)$/,
                ([id = "", verb = ""]) => {
                    const schedule = schedules.find((candidate) => candidate.id === id);

                    if (schedule === undefined) {
                        return notFound("Schedule");
                    }

                    if (verb === "activate") {
                        schedule.desired_timer_state = "enabled";
                    } else {
                        schedule.last_run_at = "2026-01-02T00:00:00+00:00";
                        schedule.last_run_status = "succeeded";
                    }

                    return ok(schedule);
                },
            ],
            ["POST", /^\/api\/v1\/doctor$/, () => ok(byRoute.get("POST /api/v1/doctor#"))],
            [
                "DELETE",
                /^\/api\/v1\/processes\/(\d+)$/,
                ([id = ""]) => {
                    const index = processes.findIndex((candidate) => String(candidate.id) === id);

                    return index === -1 ? notFound("Process") : ok(processes.splice(index, 1)[0]);
                },
            ],
            [
                "DELETE",
                /^\/api\/v1\/database-connections\/([^/]+)$/,
                ([slug = ""]) => {
                    const index = databases.findIndex((candidate) => candidate.slug === slug);

                    return index === -1
                        ? notFound("Database connection")
                        : ok(databases.splice(index, 1)[0]);
                },
            ],
            [
                "DELETE",
                /^\/api\/v1\/nodes\/(\d+)\/firewall-rules\/([^/]+)$/,
                ([node = "", name = ""]) => {
                    const onNode = rules(node);
                    const index = onNode.findIndex(
                        (candidate) => candidate.name === decodeURIComponent(name),
                    );

                    return index === -1
                        ? notFound("Firewall rule")
                        : ok(onNode.splice(index, 1)[0]);
                },
            ],
            [
                "POST",
                /^\/api\/v1\/nodes$/,
                (_, body) => {
                    const roles = Array.isArray(body.roles) ? (body.roles as string[]) : [];

                    // The one refusal the form can reach: an app-dev Node needs a TLD.
                    if (roles.includes("app-dev") && typeof body.tld !== "string") {
                        const refusal = recordedFixture("tld-required");

                        return { status: refusal.status, payload: refusal.body };
                    }

                    const created = {
                        ...(recordedFixture("created").body.data as Node),
                        id: Math.max(...nodes.map((node) => node.id)) + 1,
                        name: String(body.name),
                        roles,
                        tld: typeof body.tld === "string" ? body.tld : null,
                    };
                    nodes.push(created);

                    return ok(created, 201);
                },
            ],
        ];

    const documents = createDemoDocuments();
    const transport: Transport = async (method, path, body) => {
        requests.push({ method, path, body });
        const documentAnswer = await documents(method, path, body);
        if (documentAnswer !== undefined) return documentAnswer;

        for (const [routeMethod, pattern, answer] of routes) {
            const match = method === routeMethod ? pattern.exec(path) : null;

            if (match !== null) {
                return Promise.resolve(
                    structuredClone(
                        answer(match.slice(1), (body ?? {}) as Record<string, unknown>),
                    ),
                );
            }
        }

        return Promise.resolve(
            failure(404, "route.not_found", `The demo Gateway has no route ${method} ${path}.`),
        );
    };

    return {
        transport,
        requests,
        enableProxyCli(): void {
            proxycliExtensionEnabled = true;
            proxycliConfigured = true;
        },
        enableProxyCliExtension(): void {
            proxycliExtensionEnabled = true;
        },
        teardownProxyCli(): void {
            proxycliConfigured = false;
        },
        disableProxyCliExtension(): void {
            proxycliExtensionEnabled = false;
        },
        disableTasks(): void {
            tasksEnabled = false;
        },
    };
}
