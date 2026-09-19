export type AnnotationRect = {
    x: number;
    y: number;
    width: number;
    height: number;
};

export type AnnotationStatus = "pending" | "in_progress" | "applied" | "resolved";

export type AppearanceSnapshot = {
    classes: string[];
    computed: Record<string, string>;
    utilities: Record<string, string>;
};

export type ComponentFrame = {
    name: string;
    file?: string;
    line?: number;
};

export type VisualChange = {
    simple?: boolean;
    applied?: boolean;
    from?: string;
    to?: string;
    axis?: string;
    file?: string;
    confidence?: number;
    reason?: string;
};

export type Annotation = {
    id: string;
    x: number;
    y: number;
    comment: string;
    element: string;
    elementPath: string;
    timestamp: number;
    status?: AnnotationStatus;
    boundingBox?: AnnotationRect;
    isFixed?: boolean;
    url?: string;
    pathname?: string;
    component?: string;
    controller?: string;
    route?: string;
    screenSize?: string;
    scrollPosition?: string;
    breakpoint?: string;
    screenshot?: string;
    react?: string;
    components?: ComponentFrame[];
    appearance?: AppearanceSnapshot;
    change?: VisualChange;
};

export type AnnotationDraft = {
    annotationId?: string;
    x: number;
    y: number;
    clientX: number;
    clientY: number;
    element: string;
    elementPath: string;
    boundingBox: AnnotationRect;
    isFixed: boolean;
    comment: string;
    targetElement?: HTMLElement;
    url?: string;
    pathname?: string;
    component?: string;
    controller?: string;
    route?: string;
    screenSize?: string;
    scrollPosition?: string;
    breakpoint?: string;
    screenshot?: string;
    react?: string;
    components?: ComponentFrame[];
    appearance?: AppearanceSnapshot;
};

export type HoverTarget = {
    name: string;
    path: string;
    reactComponents?: string | null;
    rect: AnnotationRect;
    cursorX: number;
    cursorY: number;
};
