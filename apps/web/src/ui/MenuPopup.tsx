import { Frame } from "./Frame";
import { chooseAction } from "./menu";
import { ui, useUi } from "./store";

const WIDTH_CH = 44;

/** A small box over the screen listing the actions for the chosen row, at the pointer or in the middle. */
export function MenuPopup() {
    const menu = useUi((state) => state.menu);

    if (menu === null) {
        return null;
    }

    const active = menu.actions[menu.selected];
    const position =
        menu.at === null
            ? { left: "50%", top: "50%", transform: "translate(-50%, -50%)" }
            : {
                  left: `min(${menu.at[0]}px, calc(100vw - ${WIDTH_CH + 2}ch))`,
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
                style={{ width: `${WIDTH_CH}ch`, ...position }}
                onMouseDown={(event) => event.stopPropagation()}
            >
                <Frame
                    title={menu.title}
                    state="focused"
                    bottomRight={
                        menu.confirm ? "Enter confirms · Esc cancels" : "Enter runs · Esc closes"
                    }
                >
                    {menu.actions.map((action, index) => (
                        <div
                            key={action.label}
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
                    <div className="mt-[20px] whitespace-normal text-dim">
                        {menu.running
                            ? "Running…"
                            : menu.confirm
                              ? `Confirm? ${active?.description ?? ""}`
                              : (active?.description ?? "")}
                    </div>
                </Frame>
            </div>
        </div>
    );
}
