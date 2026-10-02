import { configureOrbit, type OrbitOptions } from "./orbit";
export type { OrbitOptions } from "./orbit";
import type { AnnotationRealtime } from "./realtime";
export type { AnnotationRealtime } from "./realtime";
import { configureAnnotationService } from "./sync";
import { configureThread, type ThreadOptions } from "./thread";
import { configureCommander, type CommanderConfig } from "./commander";
import { configureToolbarData, type ToolbarData } from "./core/request-history";
import { ensureAnnotationRuntime, teardownAnnotationRuntime } from "./runtime";
import type { DictationInput } from "./dictation-settings";

export type AnnotationOptions = {
    floatingControl?: boolean;
    serviceUrl?: string;
    /** Annotation server endpoint (not the Orbit Tasks API). */
    serverUrl?: string;
    orbit?: OrbitOptions;
    realtime?: AnnotationRealtime;
    thread?: ThreadOptions;
    dictation?: DictationInput;
    commander?: Partial<CommanderConfig>;
    getToolbarData?: () => ToolbarData;
};

/** Mount once per page; repeated calls update configuration without duplicating controls. */
export function mountAnnotation(options: AnnotationOptions = {}) {
    configureCommander({
        enabled: false,
        project: "commander",
        endpoint: "/__orbit/commander/one-shot",
        ...options.commander,
    });
    configureOrbit(options.serviceUrl, options.orbit);
    configureAnnotationService(options.serviceUrl, options.realtime, undefined, options.serverUrl);
    configureThread(options.thread);
    configureToolbarData(options.getToolbarData);
    ensureAnnotationRuntime(options);
    return { destroy: teardownAnnotationRuntime };
}

export { configureCommander } from "./commander";
export {
    clearAllAnnotations,
    clearPageAnnotations,
    ensureAnnotationRuntime,
    teardownAnnotationRuntime,
    toggleAnnotationMode,
    setAnnotationMode,
} from "./runtime";
export type { Annotation } from "./types";
export type { DictationSettings } from "./dictation";
export type { ToolbarData } from "./core/request-history";

export {
    getAnnotationState,
    subscribeAnnotationState,
    getAnnotationSettings,
    saveAnnotationSettings,
} from "./host-controls";
export { checkAnnotationServer, type DeliveryMode } from "./sync";
export { checkOrbit } from "./orbit";
