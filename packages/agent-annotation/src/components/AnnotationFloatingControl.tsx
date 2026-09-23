import { checkOrbit, orbitAvailability } from "../orbit";
import { ChevronDownIcon } from "@heroicons/react/16/solid";
import { useStore } from "../core/store";
import {
    serviceSettings,
    saveServiceSettings,
    serviceConnection,
    checkAnnotationServer,
    type DeliveryMode,
    deliveryMode,
    localSessionCount,
} from "../sync";
import { useEffect, useRef, useState } from "react";
import { selectThread, threadSelection } from "../thread";
import {
    ChatBubbleBottomCenterTextIcon,
    TrashIcon,
    Cog6ToothIcon,
} from "@heroicons/react/16/solid";
import { annotationMode, annotations, clearAllAnnotations, toggleAnnotationMode } from "../runtime";
import { useRefValue } from "../state";
import { cn } from "../lib/utils";

/** Shared floating pill for annotation mode and clearing saved pins. */
export function AnnotationFloatingControl() {
    const connection = useStore(serviceConnection);
    const orbit = useStore(orbitAvailability);
    const [settingsOpen, setSettingsOpen] = useState(false);
    useEffect(() => {
        if (settingsOpen) void checkOrbit();
    }, [settingsOpen]);
    const [service, setService] = useState(serviceSettings);
    const [error, setError] = useState("");
    const [serverCheck, setServerCheck] = useState<
        "idle" | "checking" | "reachable" | "unavailable"
    >("idle");
    const checkRequest = useRef<AbortController | null>(null);
    useEffect(() => () => checkRequest.current?.abort(), []);
    const resetServerCheck = () => {
        checkRequest.current?.abort();
        setServerCheck("idle");
    };
    const checkServer = async () => {
        checkRequest.current?.abort();
        if (!service.serviceUrl.trim()) {
            setServerCheck("idle");
            return;
        }
        const controller = new AbortController();
        checkRequest.current = controller;
        setServerCheck("checking");
        try {
            await checkAnnotationServer(service.serviceUrl, controller.signal);
            if (!controller.signal.aborted) setServerCheck("reachable");
        } catch {
            if (!controller.signal.aborted) setServerCheck("unavailable");
        }
    };
    const savedService = serviceSettings();
    const serverStatus =
        serverCheck !== "idle"
            ? serverCheck
            : service.serviceUrl &&
                service.serviceUrl === savedService.serviceUrl &&
                savedService.mode === "server"
              ? connection === "Connected"
                  ? "reachable"
                  : connection === "Unavailable"
                    ? "unavailable"
                    : "checking"
              : "idle";

    const thread = useRefValue(threadSelection);
    const [threadInput, setThreadInput] = useState<string | null>(null);
    const isActive = useRefValue(annotationMode);
    const visibleCount = useRefValue(annotations).length;
    const sessionCount = useStore(localSessionCount);
    const mode = useStore(deliveryMode);
    const count = mode === "server" ? Math.max(sessionCount, visibleCount) : visibleCount;

    return (
        <div
            data-feedback-toolbar=""
            data-orbit-annotation-fab=""
            className={cn(
                "pointer-events-auto fixed right-5 bottom-5 z-[2147483646] flex origin-bottom-right scale-[0.825] items-center rounded-full border border-white/10 p-1 shadow-lg backdrop-blur-xl transition-colors",
                isActive ? "bg-white text-black" : "bg-[#111111]/92 text-white",
            )}
        >
            {settingsOpen ? (
                <form
                    aria-label="Annotation settings"
                    className="absolute right-0 bottom-full mb-3 w-[25rem] max-w-[calc(100vw-3rem)] rounded-xl border border-white/15 bg-[#171717] p-4 text-sm text-white shadow-lg"
                    onSubmit={(event) => {
                        event.preventDefault();
                        try {
                            saveServiceSettings(service.serviceUrl, service.mode);
                        } catch (cause) {
                            setError(cause instanceof Error ? cause.message : "Invalid settings");
                            return;
                        }
                        if (threadInput !== null) selectThread(threadInput);
                        setSettingsOpen(false);
                    }}
                >
                    <label className="mb-3 block">
                        Delivery mode
                        <div className="relative mt-1">
                            <select
                                aria-label="Delivery mode"
                                value={service.mode}
                                onChange={(event) =>
                                    setService({
                                        ...service,
                                        mode: event.target.value as DeliveryMode,
                                    })
                                }
                                className="w-full appearance-none rounded border border-white/25 bg-[#171717] py-2 pr-9 pl-2 text-white"
                            >
                                <option value="server">Local server</option>
                                <option value="orbit" disabled={orbit.state !== "available"}>
                                    Orbit
                                </option>
                            </select>
                            <ChevronDownIcon
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2"
                            />
                        </div>
                    </label>
                    {orbit.state !== "available" && (
                        <p role="status" className="mb-3 text-xs text-white/60">
                            {orbit.reason}
                        </p>
                    )}
                    <div hidden={service.mode !== "orbit"}>
                        <label htmlFor="annotate-thread-id" className="mb-2 block">
                            T3 thread ID
                        </label>
                        <input
                            id="annotate-thread-id"
                            value={threadInput ?? thread.id}
                            onChange={(event) => setThreadInput(event.target.value)}
                            placeholder="Enter thread ID"
                            className="w-full rounded border border-white/25 bg-black/30 p-2 text-white"
                        />
                        <p className="mt-2 break-words text-xs text-white/60">
                            {thread.manual ? "Saved for this tab" : thread.status}
                        </p>
                    </div>
                    <div hidden={service.mode !== "server"}>
                        <label className="mt-3 block">
                            Annotation server URL
                            <input
                                value={service.serviceUrl}
                                onChange={(event) => {
                                    resetServerCheck();
                                    setService({
                                        ...service,
                                        serviceUrl: event.target.value,
                                    });
                                }}
                                onBlur={() => void checkServer()}
                                onKeyDown={(event) => {
                                    if (event.key !== "Enter") return;
                                    event.preventDefault();
                                    event.stopPropagation();
                                    void checkServer();
                                }}
                                aria-describedby="annotate-server-status"
                                placeholder="Paste the URL printed by annotate serve"
                                className="mt-1 w-full rounded border border-white/25 bg-black/30 p-2 text-white"
                            />
                        </label>
                        <p
                            id="annotate-server-status"
                            role="status"
                            className={cn(
                                "mt-2 text-xs",
                                serverStatus === "reachable"
                                    ? "text-green-400"
                                    : serverStatus === "unavailable"
                                      ? "text-red-300"
                                      : "text-white/60",
                            )}
                        >
                            {serverStatus === "checking"
                                ? "Checking server…"
                                : serverStatus === "reachable"
                                  ? "Server reachable"
                                  : serverStatus === "unavailable"
                                    ? "Server unavailable — check the URL and that serve is running."
                                    : "Press Enter or leave the field to check the server."}
                        </p>
                    </div>
                    {error ? (
                        <p role="alert" className="mt-2 text-xs text-red-300">
                            {error}
                        </p>
                    ) : null}
                    <div className="mt-6 flex gap-3">
                        <button type="submit" className="rounded bg-white px-3 py-1 text-black">
                            Save
                        </button>
                        <button
                            type="button"
                            aria-label="Close settings"
                            onClick={() => setSettingsOpen(false)}
                        >
                            Close
                        </button>
                    </div>
                </form>
            ) : null}
            <button
                type="button"
                aria-label="Annotation settings"
                aria-expanded={settingsOpen}
                className="inline-flex size-9 items-center justify-center rounded-full bg-transparent text-inherit hover:bg-black/10"
                onClick={() => {
                    resetServerCheck();
                    setThreadInput(null);
                    setService(serviceSettings());
                    setError("");
                    setSettingsOpen(!settingsOpen);
                }}
            >
                <Cog6ToothIcon className="size-4" aria-hidden="true" />
            </button>
            <button
                type="button"
                aria-label="Clear all annotations"
                title="Clear all annotations on this site (⌘⇧R / Ctrl+Shift+R)"
                aria-keyshortcuts="Meta+Shift+R Control+Shift+R"
                className={cn(
                    "inline-flex size-9 items-center justify-center rounded-full bg-transparent text-inherit transition-colors",
                    isActive ? "hover:bg-black/10" : "hover:bg-white/10",
                )}
                onClick={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    clearAllAnnotations();
                }}
                onMouseDown={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                }}
            >
                <TrashIcon className="size-4" aria-hidden="true" />
            </button>
            <button
                type="button"
                data-orbit-annotation-chrome=""
                data-active={isActive ? "" : undefined}
                aria-pressed={isActive}
                aria-label={isActive ? "Exit annotation mode" : "Enter annotation mode"}
                title="Toggle annotation mode (⌘⇧A / Ctrl+Shift+A)"
                aria-keyshortcuts="Meta+Shift+A Control+Shift+A"
                className={cn(
                    "relative inline-flex size-9 items-center justify-center rounded-full bg-transparent text-inherit transition-colors",
                    isActive ? "hover:bg-black/10" : "hover:bg-white/10",
                )}
                onClick={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    toggleAnnotationMode();
                }}
                onMouseDown={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                }}
            >
                <ChatBubbleBottomCenterTextIcon className="size-5" aria-hidden="true" />
                {count > 0 ? (
                    <span
                        data-annotation-count
                        className={cn(
                            "absolute -top-1 -right-1 inline-flex min-w-[1.1rem] items-center justify-center rounded-full px-1 text-[0.65rem] leading-4",
                            isActive ? "bg-black/15 text-[#111]" : "bg-white text-[#111]",
                        )}
                    >
                        {count}
                    </span>
                ) : null}
            </button>
        </div>
    );
}
