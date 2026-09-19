import { ArrowUpIcon, Cog6ToothIcon, MicrophoneIcon, TrashIcon } from "@heroicons/react/16/solid";
import {
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type KeyboardEvent,
    type ReactNode,
} from "react";
import AnnotationMetadata from "@/annotation/components/AnnotationMetadata";
import {
    POPUP_CONTROL_SIZE,
    POPUP_HEIGHT,
    POPUP_WIDTH,
    percentToViewportX,
    popupPosition,
} from "@/annotation/dom";
import { createRef, dictationSettings, useRefValue, viewportTick } from "@/annotation/state";
import { useDictation } from "@/annotation/useDictation";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import type { AnnotationDraft } from "@/annotation/types";

type AnnotationPopupProps = {
    draft: AnnotationDraft;
    shakeToken?: number;
    onSubmit: (comment: string) => void;
    onCancel: () => void;
    onDelete: () => void;
};

const controlClass =
    "inline-flex size-[var(--toolbar-control-size)] items-center justify-center rounded-full border-0 !text-[length:var(--toolbar-font-size)] leading-none shadow-none";
const solidControlClass = "bg-white text-[#111111] hover:bg-white/80 hover:text-[#111111]";
const iconClass = "size-[var(--toolbar-icon-size)]";

function IconButton({
    label,
    onClick,
    className,
    disabled = false,
    testId,
    expanded,
    children,
}: {
    label: string;
    onClick: () => void;
    className?: string;
    disabled?: boolean;
    testId?: string;
    expanded?: boolean;
    children: ReactNode;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            data-annotation-settings={testId === "settings" ? true : undefined}
            data-annotation-delete={testId === "delete" ? true : undefined}
            data-annotation-submit={testId === "submit" ? true : undefined}
            className={cn(controlClass, className)}
            aria-label={label}
            aria-expanded={expanded}
            disabled={disabled}
            onClick={onClick}
        >
            {children}
        </Button>
    );
}

export default function AnnotationPopup({
    draft,
    shakeToken = 0,
    onSubmit,
    onCancel,
    onDelete,
}: AnnotationPopupProps) {
    const [commentRef] = useState(() => createRef(draft.comment ?? ""));
    const comment = useRefValue(commentRef);
    const textarea = useRef<HTMLTextAreaElement | null>(null);
    const popup = useRef<HTMLDivElement | null>(null);
    const box = useRef<HTMLDivElement | null>(null);
    const [popupHeight, setPopupHeight] = useState(POPUP_HEIGHT);
    const [shaking, setShaking] = useState(false);
    const [fieldHeight, setFieldHeight] = useState(POPUP_CONTROL_SIZE);
    const [controlSize, setControlSize] = useState(POPUP_CONTROL_SIZE);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [expandedField, setExpandedField] = useState(() =>
        Boolean(draft.annotationId || draft.comment?.trim()),
    );
    const [hasStoppedRecording, setHasStoppedRecording] = useState(false);
    const [leftWidth, setLeftWidth] = useState(POPUP_CONTROL_SIZE);
    const [rightWidth, setRightWidth] = useState(POPUP_CONTROL_SIZE);
    const compactFieldWidth = useRef(0);
    const wasRecording = useRef(false);
    const previousPhase = useRef({ stacked: false, expandedField: false });
    const layoutFrom = useRef({ height: 0, left: 0, top: 0, width: 0 });
    const leftCluster = useRef<HTMLDivElement | null>(null);
    const rightCluster = useRef<HTMLDivElement | null>(null);
    const shakeTimer = useRef(0);
    const expandTimer = useRef(0);
    const previousShake = useRef(shakeToken);
    useRefValue(viewportTick);
    const settings = useRefValue(dictationSettings);
    const isEditing = Boolean(draft.annotationId);

    const {
        enabled: dictationEnabled,
        state: dictationState,
        emptySpeech,
        elapsedMs,
        levels,
        toggle: toggleDictation,
        onEnter: onDictationEnter,
        onEscape: onDictationEscape,
    } = useDictation({
        comment: commentRef,
        autoStart: !isEditing && settings.autoStart !== false,
        settings,
        onSubmit: () => submit(),
    });

    const stacked = isEditing || Boolean(comment.trim()) || hasStoppedRecording;
    const pad = 8;
    const rowGap = 6;
    const stackGap = 10;
    const compactHeight = pad * 2 + controlSize;
    const stackedHeight = pad * 2 + fieldHeight + stackGap + controlSize;
    const fieldLeft = stacked ? pad : pad + leftWidth + rowGap;
    const fieldTop = stacked ? pad : Math.max(0, pad + (controlSize - fieldHeight) / 2 - 2);
    const measuredFieldWidth = Math.max(
        64,
        POPUP_WIDTH - pad * 2 - leftWidth - rowGap * 2 - rightWidth,
    );

    if (!stacked) {
        compactFieldWidth.current = measuredFieldWidth;
    }

    const fieldWidth = expandedField
        ? POPUP_WIDTH - pad * 2
        : compactFieldWidth.current || measuredFieldWidth;
    const anchorX = percentToViewportX(draft.x);
    const anchorY = draft.isFixed ? draft.y : draft.y - window.scrollY;
    const position = popupPosition(anchorX, anchorY, {
        width: POPUP_WIDTH,
        height: Math.max(POPUP_HEIGHT, popupHeight),
    });
    const placeholder =
        dictationState === "recording"
            ? "Listening…"
            : dictationState === "transcribing"
              ? "Transcribing…"
              : emptySpeech
                ? "No speech detected"
                : "What should change?";
    const total = Math.floor(elapsedMs / 1000);
    const elapsedLabel = `${Math.floor(total / 60)}:${String(total % 60).padStart(2, "0")}`;
    const canSubmit = Boolean(comment.trim());

    const readControlSize = () => {
        const node = box.current;

        if (!node) {
            return POPUP_CONTROL_SIZE;
        }

        const next = Number.parseFloat(
            getComputedStyle(node).getPropertyValue("--toolbar-control-size"),
        );

        return Number.isFinite(next) && next > 0 ? next : POPUP_CONTROL_SIZE;
    };

    const fitField = () => {
        const field = textarea.current;

        if (!field) {
            return;
        }

        const nextControl = readControlSize();
        setControlSize(nextControl);

        if (!stacked) {
            const compactLine = Number.parseFloat(getComputedStyle(field).lineHeight);
            setFieldHeight(
                Number.isFinite(compactLine) && compactLine > 0 ? compactLine : nextControl,
            );
            return;
        }

        const styles = getComputedStyle(field);
        const lineHeight = Number.parseFloat(styles.lineHeight);
        const paddingTop = Number.parseFloat(styles.paddingTop);
        const minHeight =
            (Number.isFinite(lineHeight) ? lineHeight : nextControl) +
            (Number.isFinite(paddingTop) ? paddingTop * 2 : 0);
        setFieldHeight(Math.max(minHeight, field.scrollHeight || minHeight));
    };

    const focusField = () => {
        window.requestAnimationFrame(() => {
            const field = textarea.current;
            if (!field) return;
            field.focus();
            field.selectionStart = field.selectionEnd = field.value.length;
        });
    };

    function submit() {
        const text = (textarea.current?.value ?? commentRef.value).trim();

        if (!text) {
            return;
        }

        commentRef.value = text;
        onSubmit(text);
    }

    const onKeydown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        event.stopPropagation();

        if (event.nativeEvent.isComposing) {
            return;
        }

        if (event.key === "Enter" && !event.shiftKey) {
            event.preventDefault();

            if (onDictationEnter()) {
                return;
            }

            submit();
        }

        if (event.key === "Escape") {
            event.preventDefault();

            if (onDictationEscape()) {
                return;
            }

            onCancel();
        }
    };

    useEffect(() => {
        if (dictationState === "idle" && commentRef.value.trim()) {
            focusField();
        }
    }, [dictationState]);

    useEffect(() => {
        if (stacked && dictationState !== "recording") {
            focusField();
        }
    }, [stacked, dictationState]);

    useEffect(() => {
        if (previousShake.current === shakeToken) {
            return;
        }

        previousShake.current = shakeToken;
        window.clearTimeout(shakeTimer.current);
        setShaking(true);
        shakeTimer.current = window.setTimeout(() => {
            setShaking(false);
            if (dictationState === "idle") {
                focusField();
            }
        }, 250);
    }, [shakeToken, dictationState]);

    useEffect(() => {
        commentRef.value = draft.comment ?? "";
        if (!dictationEnabled || isEditing) {
            focusField();
        }
    }, [draft.annotationId]);

    useEffect(() => {
        if (!dictationEnabled || isEditing || settings.autoStart === false) {
            focusField();
        }

        return () => {
            window.clearTimeout(shakeTimer.current);
            window.clearTimeout(expandTimer.current);
        };
    }, []);

    useEffect(() => {
        if (dictationState === "recording") {
            wasRecording.current = true;
            return;
        }

        if (wasRecording.current) {
            setHasStoppedRecording(true);
        }
    }, [dictationState]);

    useLayoutEffect(() => {
        window.clearTimeout(expandTimer.current);

        if (stacked) {
            expandTimer.current = window.setTimeout(
                () => setExpandedField(true),
                layoutFrom.current.height > 0 ? 160 : 0,
            );
            return () => window.clearTimeout(expandTimer.current);
        }

        setExpandedField(false);
    }, [stacked]);

    useLayoutEffect(() => {
        const to = {
            height: stacked ? stackedHeight : compactHeight,
            left: fieldLeft,
            top: fieldTop,
            width: fieldWidth,
        };
        const phaseChanged =
            previousPhase.current.stacked !== stacked ||
            previousPhase.current.expandedField !== expandedField;
        const from = layoutFrom.current;
        const boxNode = box.current;
        const fieldNode = textarea.current;
        const reduceMotion =
            window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;

        if (
            phaseChanged &&
            from.height > 0 &&
            Number.isFinite(to.height) &&
            boxNode &&
            fieldNode &&
            typeof boxNode.animate === "function" &&
            !reduceMotion
        ) {
            const easing = "cubic-bezier(0.22, 1, 0.36, 1)";
            const animations: Animation[] = [];

            if (previousPhase.current.stacked !== stacked) {
                animations.push(
                    boxNode.animate(
                        [{ height: `${from.height}px` }, { height: `${to.height}px` }],
                        {
                            duration: 280,
                            easing,
                            fill: "forwards",
                        },
                    ),
                    fieldNode.animate(
                        [
                            { left: `${from.left}px`, top: `${from.top}px` },
                            { left: `${to.left}px`, top: `${to.top}px` },
                        ],
                        { duration: 280, easing, fill: "forwards" },
                    ),
                );
            }

            if (previousPhase.current.expandedField !== expandedField && from.width !== to.width) {
                fieldNode.getAnimations().forEach((animation) => animation.cancel());
                animations.push(
                    fieldNode.animate([{ width: `${from.width}px` }, { width: `${to.width}px` }], {
                        duration: 280,
                        easing,
                        fill: "forwards",
                    }),
                );
            }

            void Promise.all(animations.map((animation) => animation.finished))
                .catch(() => undefined)
                .then(() => {
                    boxNode.getAnimations().forEach((animation) => animation.cancel());
                    fieldNode.getAnimations().forEach((animation) => animation.cancel());
                });
        }

        previousPhase.current = { stacked, expandedField };
        layoutFrom.current = to;
    }, [stacked, expandedField, stackedHeight, compactHeight, fieldLeft, fieldTop, fieldWidth]);

    useLayoutEffect(() => {
        const left = leftCluster.current;
        const right = rightCluster.current;
        const update = () => {
            if (left) {
                setLeftWidth(left.offsetWidth);
            }

            if (right) {
                setRightWidth(right.offsetWidth);
            }
        };

        update();

        if (typeof ResizeObserver === "undefined") {
            return;
        }

        const observer = new ResizeObserver(update);

        if (left) {
            observer.observe(left);
        }

        if (right) {
            observer.observe(right);
        }

        return () => observer.disconnect();
    }, [stacked, dictationState, canSubmit, isEditing, controlSize]);

    useLayoutEffect(() => {
        fitField();
        setPopupHeight(popup.current?.offsetHeight ?? POPUP_HEIGHT);
    }, [
        comment,
        placeholder,
        stacked,
        draft.annotationId,
        fieldHeight,
        controlSize,
        detailsOpen,
        expandedField,
    ]);

    useEffect(() => {
        const field = textarea.current;

        if (!field) {
            return;
        }

        const syncValue = () => {
            commentRef.value = field.value;
            fitField();
        };

        const onNativeKeyDown = (event: globalThis.KeyboardEvent) => {
            event.stopPropagation();

            if (event.isComposing) {
                return;
            }

            if (event.key === "Enter" && !event.shiftKey) {
                event.preventDefault();

                if (onDictationEnter()) {
                    return;
                }

                submit();
            }

            if (event.key === "Escape") {
                event.preventDefault();

                if (onDictationEscape()) {
                    return;
                }

                onCancel();
            }
        };

        field.addEventListener("input", syncValue);
        field.addEventListener("keydown", onNativeKeyDown);

        return () => {
            field.removeEventListener("input", syncValue);
            field.removeEventListener("keydown", onNativeKeyDown);
        };
    }, [stacked, dictationState]);

    const settingsButton = (
        <IconButton
            label={detailsOpen ? "Hide annotation details" : "Show annotation details"}
            onClick={() => setDetailsOpen((open) => !open)}
            testId="settings"
            expanded={detailsOpen}
            className={cn(
                "bg-white/10 text-white/80 hover:bg-white/15 hover:text-white",
                detailsOpen && "bg-white/20 text-white",
            )}
        >
            <Cog6ToothIcon className={iconClass} />
        </IconButton>
    );

    const deleteButton = isEditing ? (
        <IconButton
            label="Delete annotation"
            onClick={onDelete}
            testId="delete"
            className="bg-transparent text-white/35 hover:bg-white/10 hover:text-danger"
        >
            <TrashIcon className={iconClass} />
        </IconButton>
    ) : null;

    const recorder =
        dictationEnabled && dictationState === "recording" ? (
            <Button
                type="button"
                variant="ghost"
                className={cn(
                    controlClass,
                    "h-[var(--toolbar-control-size)] w-auto gap-1.5 bg-white/10 px-2 text-white/85 hover:bg-white/15",
                )}
                aria-label="Stop dictation"
                onClick={toggleDictation}
            >
                <span className="block size-2 rounded-[2px] bg-current" />
                <span className="leading-none tabular-nums">{elapsedLabel}</span>
                <span className="flex h-3 items-end gap-0.5 text-white/80" aria-hidden="true">
                    {levels.map((level, index) => (
                        <span
                            key={index}
                            className="w-0.5 rounded-full bg-current"
                            style={{ height: `${4 + level * 8}px` }}
                        />
                    ))}
                </span>
            </Button>
        ) : dictationEnabled ? (
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                className={cn(
                    controlClass,
                    solidControlClass,
                    dictationState === "transcribing" && "cursor-progress opacity-70",
                )}
                aria-label={dictationState === "transcribing" ? "Transcribing" : "Dictate comment"}
                onClick={toggleDictation}
            >
                {dictationState === "transcribing" ? (
                    <svg
                        className={cn("toolbar-annotation-spin", iconClass)}
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.75"
                    >
                        <circle cx="12" cy="12" r="8" opacity="0.25" />
                        <path d="M12 4a8 8 0 0 1 8 8" />
                    </svg>
                ) : (
                    <MicrophoneIcon className={iconClass} />
                )}
            </Button>
        ) : null;

    const sendButton =
        dictationState !== "recording" && (canSubmit || isEditing) ? (
            <IconButton
                label={isEditing ? "Save annotation" : "Add annotation"}
                onClick={submit}
                testId="submit"
                disabled={!canSubmit}
                className={cn("disabled:opacity-30", solidControlClass)}
            >
                <ArrowUpIcon className={iconClass} />
            </IconButton>
        ) : null;

    return (
        <div
            ref={popup}
            data-annotation-popup
            className={cn(
                "pointer-events-auto absolute w-72 text-white",
                shaking && "toolbar-annotation-shake",
            )}
            data-annotation-expanded={stacked ? "true" : "false"}
            data-field-expanded={expandedField || undefined}
            style={{ left: `${position.left}px`, top: `${position.top}px` }}
            onClick={(event) => event.stopPropagation()}
            onMouseDown={(event) => event.stopPropagation()}
        >
            <div
                ref={box}
                data-annotation-box
                className={cn(
                    "relative w-72 overflow-hidden border border-white/10 bg-[#111111]/95 shadow-none backdrop-blur-xl",
                    stacked ? "rounded-xl" : "rounded-full",
                )}
                style={{ height: stacked ? stackedHeight : compactHeight }}
            >
                <div
                    data-annotation-actions
                    className="absolute right-2 bottom-2 left-2 z-20 flex h-[var(--toolbar-control-size)] items-center gap-1.5"
                >
                    <div ref={leftCluster} className="flex items-center gap-1.5">
                        {settingsButton}
                        {stacked ? deleteButton : null}
                    </div>
                    <div ref={rightCluster} className="ml-auto flex items-center gap-1.5">
                        {!stacked ? deleteButton : null}
                        {recorder}
                        {sendButton}
                    </div>
                </div>
                <textarea
                    ref={textarea}
                    data-annotation-field
                    value={comment}
                    onChange={(event) => {
                        commentRef.value = (event.target as HTMLTextAreaElement).value;
                        fitField();
                    }}
                    className={cn(
                        "absolute z-10 m-0 resize-none overflow-hidden border-0 bg-transparent py-0 text-white outline-none placeholder:text-white/35",
                        stacked
                            ? "px-[5px] leading-[var(--toolbar-annotation-line-height)]"
                            : "px-1 leading-[var(--toolbar-line-height)]",
                    )}
                    style={{
                        left: fieldLeft,
                        top: fieldTop,
                        width: fieldWidth,
                        height: fieldHeight,
                    }}
                    placeholder={placeholder}
                    rows={1}
                    onKeyDown={onKeydown}
                />
            </div>
            {detailsOpen ? <AnnotationMetadata draft={draft} /> : null}
        </div>
    );
}
