import { Frame } from "./Frame";
import { chooseAction } from "./menu";
import { ui, useUi } from "./store";

/** A plain list of verbs is narrow; the question before a destructive action needs room to read. */
const WIDTH_CH = { list: 20, confirm: 40 };

/** A drop-down over the screen listing the actions for the chosen row, at the pointer or in the middle. It only grows text to ask before a destructive action. */
export function MenuPopup() {
    const menu = useUi((state) => state.menu);

    if (menu === null) {
        return null;
    }

    const active = menu.actions[menu.selected];
    const width = menu.confirm ? WIDTH_CH.confirm : WIDTH_CH.list;
    const position =
        menu.at === null
            ? { left: "50%", top: "50%", transform: "translate(-50%, -50%)" }
            : menu.hangsRight
              ? { right: `calc(100vw - ${menu.at[0]}px)`, top: `${menu.at[1]}px` }
              : {
                    left: `min(${menu.at[0]}px, calc(100vw - ${WIDTH_CH.confirm + 2}ch))`,
                    top: `min(${menu.at[1]}px, calc(100vh - ${(menu.actions.length + 5) * 20}px))`,
                };

    return (
        <div
            className="fixed inset-0 z-10"
            onMouseDown={() => ui.set({ menu: null })}
            onContextMenu={(event) => event.preventDefault()}
        >
            <div
                className="absolute bg-bg"
                style={{ width: `${width}ch`, ...position }}
                onMouseDown={(event) => event.stopPropagation()}
            >
                <Frame
                    label={menu.title}
                    state="focused"
                    bottomRight={menu.confirm ? "Enter confirms · Esc cancels" : undefined}
                >
                    {menu.actions.map((action, index) => (
                        <div
                            key={action.label}
                            role="menuitem"
                            className="row cursor-pointer"
                            style={{ gridTemplateColumns: "minmax(0, 1fr)" }}
                            data-selected={index === menu.selected ? "" : undefined}
                            data-focused=""
                            data-warn={action.destructive ? "" : undefined}
                            onMouseEnter={() =>
                                !menu.confirm && ui.set({ menu: { ...menu, selected: index } })
                            }
                            onClick={() => chooseAction(index)}
                        >
                            <span>{action.label}</span>
                        </div>
                    ))}
                    {(menu.running || menu.confirm) && (
                        <div className="mt-[10px] whitespace-normal text-dim">
                            {menu.running ? "Running…" : `Confirm? ${active?.description ?? ""}`}
                        </div>
                    )}
                </Frame>
            </div>
        </div>
    );
}
