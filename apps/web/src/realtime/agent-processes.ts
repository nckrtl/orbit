import { useSyncExternalStore } from "react";
import type { Instance, Process } from "../api/types";
import { agentState, agentPresenceRevision, subscribeAgentPresence } from "./agent-presence";

type Unit = { name: string; runtime: "systemd" | "docker"; runtime_status: string };
type NodeProcesses = {
    sequence: number;
    units: Map<string, Unit>;
    hasSnapshot: boolean;
    pending: { nextPart: number; parts: number; nextSequence: number; units: Unit[] } | null;
};
const states = new Map<number, NodeProcesses>();
const listeners = new Set<() => void>();
let revision = 0;
const notify = () => {
    revision++;
    listeners.forEach((listener) => listener());
};
const validUnit = (value: unknown): value is Unit => {
    if (!value || typeof value !== "object") return false;
    const unit = value as Partial<Unit>;
    return (
        typeof unit.name === "string" &&
        (unit.runtime === "systemd" || unit.runtime === "docker") &&
        typeof unit.runtime_status === "string"
    );
};
const stateFor = (nodeId: number): NodeProcesses => {
    let state = states.get(nodeId);
    if (!state) {
        state = { sequence: 0, units: new Map(), hasSnapshot: false, pending: null };
        states.set(nodeId, state);
    }
    return state;
};

/** Accept process-bearing payloads only after connect.ts has authenticated the sender/member. */
export function applyAgentProcessEvent(nodeId: number, eventName: string, payload: unknown): void {
    if (!payload || typeof payload !== "object") return;
    const data = payload as Record<string, unknown>;
    if (!Number.isSafeInteger(data.sequence) || (data.sequence as number) < 1) return;
    const state = stateFor(nodeId);
    const sequence = data.sequence as number;
    if (sequence <= state.sequence) {
        // Agent sequence numbers restart after reconnect/restart. Rebase on the new run.
        state.sequence = 0;
        state.units.clear();
        state.hasSnapshot = false;
        state.pending = null;
        notify();
    }

    if (eventName === "client-snapshot") {
        if (
            !Number.isSafeInteger(data.part) ||
            !Number.isSafeInteger(data.parts) ||
            (data.part as number) < 1 ||
            (data.parts as number) < 1 ||
            (data.part as number) > (data.parts as number) ||
            !Array.isArray(data.units) ||
            !data.units.every(validUnit)
        )
            return;
        const part = data.part as number;
        const parts = data.parts as number;
        if (part === 1) {
            state.pending = {
                nextPart: 2,
                parts,
                nextSequence: sequence + 1,
                units: [...data.units],
            };
        } else {
            const pending = state.pending;
            if (
                !pending ||
                pending.parts !== parts ||
                pending.nextPart !== part ||
                pending.nextSequence !== sequence
            ) {
                state.pending = null;
                return;
            }
            pending.units.push(...data.units);
            pending.nextPart++;
            pending.nextSequence++;
        }
        state.sequence = sequence;
        const pending = state.pending;
        if (pending && pending.nextPart > pending.parts) {
            state.units = new Map(
                pending.units.map((unit) => [`${unit.runtime}:${unit.name}`, unit]),
            );
            state.hasSnapshot = true;
            state.pending = null;
            notify();
        }
        return;
    }

    state.sequence = sequence;
    state.pending = null;
    if (eventName === "client-process") {
        if (!validUnit(data.unit)) return;
        state.units.set(`${data.unit.runtime}:${data.unit.name}`, data.unit);
        notify();
    }
}

function unitStatus(process: Process, nodeId: number | null): string | null {
    if (nodeId === null || !process.runtime) return null;
    const state = states.get(nodeId);
    if (!state?.hasSnapshot) return null;
    const name = `orbit-process-${process.id}-${process.name}`;
    return (
        state.units.get(`${process.runtime}:${name}`)?.runtime_status ??
        (process.runtime === "systemd" ? "inactive" : "exited")
    );
}

function processNodeId(process: Process, instances: Instance[]): number | null {
    if (process.target_type === "node") return process.target_id;
    return instances.find((instance) => instance.id === process.target_id)?.node.id ?? null;
}
export function agentProcessStatus(process: Process, instances: Instance[] = []): string | null {
    const nodeId = processNodeId(process, instances);
    return nodeId !== null && agentState(nodeId) === "online" ? unitStatus(process, nodeId) : null;
}
export function withAgentProcessStatuses(
    processes: Process[],
    instances: Instance[] = [],
): Process[] {
    return processes.map((process) => {
        const runtime_status = agentProcessStatus(process, instances);
        return runtime_status === null ? process : { ...process, runtime_status };
    });
}
export function clearAgentProcesses(nodeId?: number): void {
    const changed = nodeId === undefined ? states.size > 0 : states.delete(nodeId);
    if (nodeId === undefined) states.clear();
    if (changed) notify();
}
export function subscribeAgentProcesses(listener: () => void): () => void {
    listeners.add(listener);
    return () => listeners.delete(listener);
}
export function useAgentProcessStatuses(processes: Process[], instances: Instance[]): Process[] {
    useSyncExternalStore(
        (listener) => {
            const offProcesses = subscribeAgentProcesses(listener);
            const offPresence = subscribeAgentPresence(listener);
            return () => {
                offProcesses();
                offPresence();
            };
        },
        () => `${revision}:${agentPresenceRevision()}`,
        () => "server",
    );
    return withAgentProcessStatuses(processes, instances);
}
