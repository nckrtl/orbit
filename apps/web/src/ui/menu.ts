import { type Action, actionsFor } from "../api/actions";
import { GatewayError } from "../api/client";
import type { FirewallRule } from "../api/types";
import { recordTitle } from "../fleet/fleet";
import { type Target, ui } from "./store";

/** A firewall rule's menu names the rule; its page names what the rule does. Every other family uses one title. */
const menuTitle = (target: Target): string =>
    target.kind === "firewall"
        ? (target.row as FirewallRule).name
        : recordTitle(target.kind, target.row);

/** Opens the actions menu for a record, at the pointer or in the middle of the screen. */
export function openMenu(
    target: Target,
    at: [number, number] | null = null,
    hangsRight = false,
): void {
    const actions = actionsFor(target.kind, target.row);

    if (actions.length > 0) {
        ui.set({
            menu: {
                ...target,
                invocation: {},
                title: menuTitle(target),
                actions,
                selected: 0,
                confirm: false,
                running: false,
                at,
                hangsRight,
            },
        });
    }
}

/** Enter or a click on a menu item. A destructive action asks to confirm first. */
export function chooseAction(index?: number): void {
    const menu = ui.get().menu;
    const action: Action | undefined = menu?.actions[index ?? menu.selected];

    if (menu === null || action === undefined || menu.running) {
        return;
    }

    switch (action.kind) {
        case "report": {
            const title = `${action.label} · ${menu.title}`;
            const pending = { title, output: null, failed: false };

            ui.set({ menu: null, modal: pending });
            void action
                .report()
                .then(
                    ({ ok, output }) => ({ output, failed: !ok }),
                    (error: unknown) => ({ output: String(error), failed: true }),
                )
                .then((result) => {
                    if (ui.get().modal === pending) {
                        ui.set({ modal: { ...pending, ...result } });
                    }
                });

            return;
        }

        case "command": {
            const command = action.command;
            void navigator.clipboard?.writeText(command).catch(() => {});
            ui.set({ menu: null, message: `${command}  (copied; ${action.description})` });

            return;
        }
        case "run":
            break;
        default: {
            const unreachable: never = action;
            return unreachable;
        }
    }

    if (action.destructive === true && !menu.confirm) {
        ui.set({ menu: { ...menu, selected: index ?? menu.selected, confirm: true } });

        return;
    }

    ui.set({ menu: { ...menu, running: true } });
    const complete = (message: string): void => {
        ui.set({
            ...(ui.get().menu?.invocation === menu.invocation ? { menu: null } : {}),
            message,
        });
    };
    void action
        .run()
        .then(complete, (error: unknown) =>
            complete(error instanceof GatewayError ? error.message : String(error)),
        );
}
