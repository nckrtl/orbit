import { useState } from "react";
import { api, GatewayError } from "../api/client";
import { queryClient } from "../api/queryClient";
import type { Node } from "../api/types";
import { applyRow } from "../realtime/apply";
import { Frame } from "../ui/Frame";
import { useGo } from "../ui/go";
import { PageHeader } from "../ui/PageHeader";
import { ui } from "../ui/store";

const ROLES = [
    ["app-dev", "runs App instances"],
    ["app-prod", "runs production App instances"],
    ["gateway", "runs the Gateway and the VPN hub"],
] as const;

const HOST = /^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i;
const IP = /^(\d{1,3}\.){3}\d{1,3}$|^[0-9a-f:]+:[0-9a-f:]*$/i;

type Values = {
    name: string;
    host: string;
    port: string;
    user: string;
    tld: string;
    roles: string[];
};

/** The same rules the `node:add` prompts apply, so the form and the command refuse the same input. */
function validate(values: Values): Partial<Record<keyof Values, string>> {
    const errors: Partial<Record<keyof Values, string>> = {};

    if (values.name === "") {
        errors.name = "Required.";
    } else if (!/^[a-z0-9-]+$/.test(values.name)) {
        errors.name = "Use lowercase letters, digits, and dashes.";
    }

    if (values.host !== "" && !IP.test(values.host) && !HOST.test(values.host)) {
        errors.host = "Enter an IP address or a host name such as beast.example.test.";
    }

    if (!/^\d+$/.test(values.port) || Number(values.port) < 1 || Number(values.port) > 65535) {
        errors.port = "A port is a number from 1 to 65535.";
    }

    if (values.user === "") {
        errors.user = "Required.";
    }

    if (values.roles.length === 0) {
        errors.roles = "Pick at least one role.";
    }

    return errors;
}

function Field({
    label,
    error,
    hint,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <div className="field" data-error={error === undefined ? undefined : ""}>
                <span className="field-label">{label}</span>
                {children}
            </div>
            <div className="min-h-[20px] px-[1ch] whitespace-normal">
                {error !== undefined ? (
                    <span className="text-yellow">⚠ {error}</span>
                ) : (
                    <span className="text-dim">{hint}</span>
                )}
            </div>
        </div>
    );
}

/** `node:add` as a page. The request provisions synchronously, so the button says it is in flight until the Gateway answers. */
export function NodeCreate() {
    const go = useGo();
    const [values, setValues] = useState<Values>({
        name: "",
        host: "",
        port: "22",
        user: "root",
        tld: "",
        roles: ["app-dev"],
    });
    const [errors, setErrors] = useState<Partial<Record<keyof Values, string>>>({});
    const [failure, setFailure] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);
    const set = (name: keyof Values) => (event: React.ChangeEvent<HTMLInputElement>) =>
        setValues({ ...values, [name]: event.target.value });

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        const found = validate(values);
        setErrors(found);
        setFailure(null);

        if (Object.keys(found).length > 0 || creating) {
            return;
        }

        setCreating(true);

        try {
            const node = await api<Node>("POST", "/api/v1/nodes", {
                name: values.name,
                roles: values.roles,
                public_ssh_port: Number(values.port),
                user: values.user,
                ...(values.host === "" ? {} : { public_ssh_host: values.host }),
                ...(values.tld === "" ? {} : { tld: values.tld }),
            });
            applyRow(queryClient, "nodes", "created", node);
            ui.set({ message: `Node [${node.name}] is ${node.status}.` });
            go.record("nodes", node);
        } catch (error) {
            setFailure(error instanceof GatewayError ? error.message : String(error));
        } finally {
            setCreating(false);
        }
    };

    return (
        <div className="grid h-full grid-rows-[auto_minmax(0,1fr)] gap-y-[16px]">
            <PageHeader
                trail={[
                    { label: "Nodes", open: () => go.section("nodes") },
                    { label: "Create node" },
                ]}
            />
            <Frame title="New node" state="focused">
                <form
                    className="mx-[2ch] flex flex-col gap-y-[10px] pt-[30px] pb-[20px]"
                    onSubmit={(event) => void submit(event)}
                >
                    <Field label="Node name" error={errors.name}>
                        <input
                            type="text"
                            autoFocus
                            placeholder="beast"
                            value={values.name}
                            onChange={set("name")}
                        />
                    </Field>
                    <Field label="SSH host" error={errors.host}>
                        <input
                            type="text"
                            placeholder="10.0.0.12 or beast.example.test"
                            value={values.host}
                            onChange={set("host")}
                        />
                    </Field>
                    <Field label="SSH port" error={errors.port}>
                        <input
                            type="text"
                            inputMode="numeric"
                            value={values.port}
                            onChange={set("port")}
                        />
                    </Field>
                    <Field label="SSH user" error={errors.user}>
                        <input type="text" value={values.user} onChange={set("user")} />
                    </Field>
                    <Field
                        label="Roles"
                        error={errors.roles}
                        hint="Use the space bar to select options."
                    >
                        {ROLES.map(([role, does]) => {
                            const on = values.roles.includes(role);

                            return (
                                <label key={role} className="option">
                                    <input
                                        type="checkbox"
                                        className="sr-only"
                                        checked={on}
                                        onChange={() =>
                                            setValues({
                                                ...values,
                                                roles: on
                                                    ? values.roles.filter((name) => name !== role)
                                                    : [...values.roles, role],
                                            })
                                        }
                                    />
                                    <span className="pointer">›</span>{" "}
                                    <span className={on ? "text-cyan" : "text-dim"}>
                                        {on ? "◼" : "◻"}
                                    </span>{" "}
                                    {role} <span className="text-dim">· {does}</span>
                                </label>
                            );
                        })}
                    </Field>
                    <Field label="TLD for its domains" error={errors.tld}>
                        <input type="text" value={values.tld} onChange={set("tld")} />
                    </Field>
                    <div>
                        <button
                            type="submit"
                            disabled={creating}
                            className="cursor-pointer font-bold text-cyan outline-0 focus:bg-fg focus:text-bg disabled:text-dim"
                        >
                            [ {creating ? "Adding node…" : "Create node"} ]
                        </button>
                        {creating && (
                            <span className="ml-[2ch] text-dim">
                                node:add provisions synchronously; this waits until the Gateway
                                finishes or fails.
                            </span>
                        )}
                    </div>
                    {failure !== null && (
                        <div className="selectable whitespace-normal text-red">{failure}</div>
                    )}
                </form>
            </Frame>
        </div>
    );
}
