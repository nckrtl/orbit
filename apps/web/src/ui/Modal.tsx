import { Frame } from "./Frame";
import { ui, useUi } from "./store";

/** A box in the middle of the screen with the output an action printed, as its command prints it. */
export function Modal() {
    const modal = useUi((state) => state.modal);

    if (modal === null) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-10 bg-bg/70"
            role="dialog"
            aria-modal="true"
            onMouseDown={() => ui.set({ modal: null })}
        >
            <div
                className="absolute top-1/2 left-1/2 flex max-h-[80vh] w-[min(72ch,90vw)] -translate-x-1/2 -translate-y-1/2 flex-col bg-bg"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <Frame
                    title={modal.title}
                    state={modal.failed ? "warn" : "focused"}
                    bottomRight="Esc closes"
                >
                    <pre className="selectable font-mono whitespace-pre-wrap">
                        {modal.output ?? "Running…"}
                    </pre>
                </Frame>
            </div>
        </div>
    );
}
