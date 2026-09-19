import { setTransport } from "../api/client";
import { setGrafanaTransport } from "../metrics/grafana";
import { createDemoGateway } from "./gateway";
import { demoGrafana } from "./grafana";

/** Answers every Gateway and Grafana request in the page from a fresh fixture fleet. */
export function installDemo() {
    const gateway = createDemoGateway();
    setTransport(gateway.transport, "demo fleet");
    setGrafanaTransport(demoGrafana);

    return gateway;
}
