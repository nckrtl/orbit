import { request } from "node:http";

const prefix = "/__annotate/local";

/** Forward browser requests to an annotation server on the development machine. */
export function annotationServerProxy() {
    return {
        name: "annotate-local-server",
        apply: "serve",
        transformIndexHtml() {
            return [
                {
                    tag: "meta",
                    attrs: { name: "annotate-local-server-proxy", content: prefix },
                    injectTo: "head",
                },
            ];
        },
        configureServer(server) {
            server.middlewares.use(async (req, res, next) => {
                const match = req.url?.match(
                    /^\/__annotate\/local\/(\d+)\/annotations(\/events|\/claim|\/complete|\/release|\/[^/?]+\/status)?$/,
                );
                if (!match) return next();
                const port = Number(match[1]);
                const json = (status, body) => {
                    res.writeHead(status, {
                        "Content-Type": "application/json",
                        "Cache-Control": "no-store",
                    });
                    res.end(JSON.stringify(body));
                };
                if (port < 1 || port > 65535)
                    return json(400, { error: "Invalid annotation server port" });
                if (req.headers.origin && new URL(req.headers.origin).host !== req.headers.host)
                    return json(403, { error: "Use this development site's origin" });
                if (!["GET", "POST"].includes(req.method))
                    return json(405, { error: "Method not allowed" });
                try {
                    // Only bridge the annotation service, never arbitrary local applications.
                    const check = await fetch(`http://127.0.0.1:${port}/annotations`, {
                        signal: AbortSignal.timeout(3000),
                    });
                    const body = await check.json();
                    if (!check.ok || body.meta?.service !== "@nckrtl/annotate")
                        return json(502, {
                            error: "This port is not an annotation server. Restart annotate serve with the latest package.",
                        });
                    if (req.method === "GET" && !match[2]) {
                        body.meta.eventsUrl = `${prefix}/${port}/annotations/events`;
                        return json(200, body);
                    }
                    const upstream = request(
                        {
                            hostname: "127.0.0.1",
                            port,
                            path: `/annotations${match[2] ?? ""}`,
                            method: req.method,
                            headers: { "Content-Type": "application/json" },
                        },
                        (response) => {
                            res.writeHead(response.statusCode ?? 502, {
                                "Content-Type":
                                    response.headers["content-type"] ?? "application/json",
                                "Cache-Control": "no-store",
                            });
                            response.pipe(res);
                        },
                    );
                    upstream.on("error", () => {
                        if (!res.headersSent)
                            json(502, { error: "Annotation server disconnected" });
                        else res.end();
                    });
                    res.on("close", () => upstream.destroy());
                    req.pipe(upstream);
                } catch {
                    json(502, {
                        error: `Annotation server on port ${port} is unavailable on the development machine.`,
                    });
                }
            });
        },
    };
}
