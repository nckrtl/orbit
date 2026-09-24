import { useSyncExternalStore } from "react";

export type AgentState = "online" | "lost" | "not_seen";
type Entry = { member: boolean; seen: boolean; lastEventAt: number | null };
const TIMEOUT_MS = 15_000;
const entries = new Map<number, Entry>();
const listeners = new Set<() => void>();
let revision = 0;
let expiryTimer: ReturnType<typeof setTimeout> | undefined;

const notify = () => {
    revision++;
    listeners.forEach((listener) => listener());
};
export const agentPresenceRevision = (): number => revision;
const scheduleExpiry = () => {
    clearTimeout(expiryTimer);
    const now = Date.now();
    const deadlines = [...entries.values()]
        .filter((entry) => entry.member && entry.lastEventAt !== null)
        .map((entry) => entry.lastEventAt! + TIMEOUT_MS)
        .filter((deadline) => deadline > now);
    if (deadlines.length === 0) return;
    expiryTimer = setTimeout(
        () => {
            notify();
            scheduleExpiry();
        },
        Math.max(0, Math.min(...deadlines) - Date.now()),
    );
};

export function agentState(nodeId: number, now = Date.now()): AgentState {
    const entry = entries.get(nodeId);
    if (!entry?.seen) return "not_seen";
    return entry.member && entry.lastEventAt !== null && now - entry.lastEventAt < TIMEOUT_MS
        ? "online"
        : "lost";
}

function update(nodeId: number, patch: Partial<Entry>): void {
    entries.set(nodeId, {
        member: false,
        seen: false,
        lastEventAt: null,
        ...entries.get(nodeId),
        ...patch,
    });
    scheduleExpiry();
    notify();
}

export function agentMemberAdded(nodeId: number, memberId: unknown): void {
    if (memberId === `agent.${nodeId}`) update(nodeId, { member: true });
}
export function agentMemberRemoved(nodeId: number, memberId: unknown): void {
    if (memberId === `agent.${nodeId}`) update(nodeId, { member: false, lastEventAt: null });
}
export function agentSubscriptionSucceeded(nodeId: number, members: unknown): void {
    const memberMap =
        members && typeof members === "object" && "members" in members
            ? (members as { members?: unknown }).members
            : null;
    const ids = memberMap && typeof memberMap === "object" ? Object.keys(memberMap) : [];
    update(nodeId, { member: ids.includes(`agent.${nodeId}`), lastEventAt: null });
}
export function acceptAgentEvent(
    nodeId: number,
    senderId: unknown,
    _data: unknown,
    now = Date.now(),
): boolean {
    if (senderId !== `agent.${nodeId}`) return false;
    const entry = entries.get(nodeId);
    if (!entry?.member) return false;
    update(nodeId, { seen: true, lastEventAt: now });
    return true;
}
export function clearAgentPresence(nodeId: number): void {
    if (entries.delete(nodeId)) {
        scheduleExpiry();
        notify();
    }
}
export function clearAllAgentPresence(): void {
    entries.clear();
    clearTimeout(expiryTimer);
    notify();
}
export function subscribeAgentPresence(listener: () => void): () => void {
    listeners.add(listener);
    return () => listeners.delete(listener);
}
export function useAgentState(nodeId: number): AgentState {
    return useSyncExternalStore(
        subscribeAgentPresence,
        () => agentState(nodeId),
        () => "not_seen",
    );
}
export const AGENT_PRESENCE_TIMEOUT_MS = TIMEOUT_MS;
