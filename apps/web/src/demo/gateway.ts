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
} from "../api/types";

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
const notFound = (what: string): Answer =>
    failure(404, "resource.not_found", `${what} was not found.`);

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
    let proxycliEnabled = false;
    const rules = (node: string) =>
        list<FirewallRule>("GET /api/v1/nodes/{node}/firewall-rules", node);
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

    const routes: [Method, RegExp, (params: string[], body: Record<string, unknown>) => Answer][] =
        [
            [
                "GET",
                /^\/api\/v1\/proxycli$/,
                () =>
                    ok({
                        enabled: proxycliEnabled,
                        hostname: "proxycli.orbit",
                        node_id: proxycliEnabled ? 2 : null,
                        cache_connection: proxycliEnabled ? "valkey" : null,
                        collected_at: proxycliEnabled ? "2026-09-20T12:00:00Z" : null,
                    }),
            ],
            [
                "GET",
                /^\/api\/v1\/proxycli\/providers$/,
                () => (proxycliEnabled ? ok(quotaProviders()) : failure(409, "proxycli.disabled", "The proxycli extension is disabled.")),
            ],
            [
                "GET",
                /^\/api\/v1\/proxycli\/providers\/([^/]+)$/,
                ([provider = ""]) => {
                    if (!proxycliEnabled) {
                        return failure(409, "proxycli.disabled", "The proxycli extension is disabled.");
                    }

                    const pool = quotaProviders().find((row) => row.provider === provider);

                    return pool === undefined ? notFound("Provider") : ok(pool);
                },
            ],
            [
                "PATCH",
                /^\/api\/v1\/proxycli\/accounts\/([^/]+)$/,
                ([account = ""], body) => {
                    if (!proxycliEnabled) {
                        return failure(409, "proxycli.disabled", "The proxycli extension is disabled.");
                    }

                    const row = quotaAccounts.find((candidate) => candidate.id === decodeURIComponent(account));

                    if (row === undefined) {
                        return notFound("Account");
                    }

                    row.disabled = body.disabled === true;

                    return ok(row);
                },
            ],
            ["GET", /^\/api\/v1\/realtime$/, () => ok(recordedFixture("unconfigured").body.data)],
            ["GET", /^\/api\/v1\/nodes$/, () => ok(nodes)],
            ["GET", /^\/api\/v1\/apps$/, () => ok(list("GET /api/v1/apps"))],
            ["GET", /^\/api\/v1\/instances$/, () => ok(list("GET /api/v1/instances"))],
            ["GET", /^\/api\/v1\/processes$/, () => ok(processes)],
            ["GET", /^\/api\/v1\/schedules$/, () => ok(schedules)],
            ["GET", /^\/api\/v1\/database-connections$/, () => ok(databases)],
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

    const transport: Transport = (method, path, body) => {
        requests.push({ method, path, body });

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
            proxycliEnabled = true;
        },
    };
}
