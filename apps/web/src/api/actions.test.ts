import { expectTypeOf, it } from "vite-plus/test";
import type { Action } from "./actions";

type Display = { label: string; description: string };
type Run = () => Promise<string>;
type Report = () => Promise<{ ok: boolean; output: string }>;

it("requires exactly one action behavior and its matching discriminator", () => {
    expectTypeOf<Display & { kind: "command"; command: string }>().toExtend<Action>();
    expectTypeOf<Display & { kind: "run"; run: Run }>().toExtend<Action>();
    expectTypeOf<Display & { kind: "report"; report: Report }>().toExtend<Action>();

    expectTypeOf<Display & { kind: "command" }>().not.toExtend<Action>();
    expectTypeOf<Display & { kind: "run" }>().not.toExtend<Action>();
    expectTypeOf<Display & { kind: "report" }>().not.toExtend<Action>();
    expectTypeOf<Display & { run: Run }>().not.toExtend<Action>();
    expectTypeOf<Display & { kind: "run"; command: string }>().not.toExtend<Action>();
    expectTypeOf<Display & { kind: "command"; command: string; run: Run }>().not.toExtend<Action>();
    expectTypeOf<
        Display & { kind: "report"; report: Report; command: string }
    >().not.toExtend<Action>();
    expectTypeOf<Display & { kind: "run"; run: Run; report: Report }>().not.toExtend<Action>();
});

it("allows destructive confirmation only for request actions", () => {
    expectTypeOf<Display & { kind: "run"; run: Run; destructive: true }>().toExtend<Action>();
    expectTypeOf<
        Display & { kind: "command"; command: string; destructive: true }
    >().not.toExtend<Action>();
    expectTypeOf<
        Display & { kind: "report"; report: Report; destructive: true }
    >().not.toExtend<Action>();
});
