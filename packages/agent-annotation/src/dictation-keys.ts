type KeyHandler = () => boolean;

let enterHandler: KeyHandler | null = null;
let escapeHandler: KeyHandler | null = null;

export function setDictationKeyHandlers(
    handlers: { onEnter?: KeyHandler; onEscape?: KeyHandler } | null,
): void {
    enterHandler = handlers?.onEnter ?? null;
    escapeHandler = handlers?.onEscape ?? null;
}

export function consumeDictationEnter(): boolean {
    return enterHandler?.() ?? false;
}

export function consumeDictationEscape(): boolean {
    return escapeHandler?.() ?? false;
}
