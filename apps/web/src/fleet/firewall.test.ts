import { describe, expect, it } from "vite-plus/test";
import type { FirewallRule } from "../api/types";
import { firewallLineTone, firewallPort, firewallSource } from "./firewall";

const rule = (status: FirewallRule["status"]): FirewallRule =>
    ({
        id: 1,
        node_id: 2,
        node: "beast",
        name: "https-public",
        action: "allow",
        source: "any",
        protocol: "tcp",
        port: "443",
        status,
        backend_status: null,
        failed_step: null,
        error_code: null,
    }) as FirewallRule;

describe("firewall line tone", () => {
    it("paints unmanaged and shape-drift live rules red", () => {
        expect(firewallLineTone({ match: "unmanaged", rule: null })).toBe("danger");
        expect(firewallLineTone({ match: "drift", rule: null })).toBe("danger");
    });

    it("paints a desired rule missing from live yellow", () => {
        expect(firewallLineTone({ match: "missing", rule: null })).toBe("warn");
    });

    it("paints a failed operator rule yellow and an exact live match ordinary", () => {
        expect(firewallLineTone({ match: "exact", rule: rule("failed") })).toBe("warn");
        expect(firewallLineTone({ match: "exact", rule: rule("active") })).toBe("ok");
        expect(firewallLineTone({ match: null, rule: null })).toBe("ok");
    });
});

describe("firewall display", () => {
    it("shows any without a protocol and names the interface on the source", () => {
        expect(firewallPort("any", "any")).toBe("any");
        expect(firewallPort("22", "tcp")).toBe("22/tcp");
        expect(firewallSource("any", "orbit")).toBe("any on orbit");
        expect(firewallSource("any", null)).toBe("any");
    });
});
