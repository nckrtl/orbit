import { useQuery } from "@tanstack/react-query";
import type { Annotation } from "@nckrtl/annotate";
import { api } from "../api/client";
import { Frame, Note } from "../ui/Frame";

export function AnnotationsPanel({ instanceId }: { instanceId: number }) {
    const endpoint = `/api/v1/instances/${instanceId}/annotations`;
    const key = ["instance-annotations", instanceId];
    const query = useQuery({
        queryKey: key,
        queryFn: () => api<Annotation[]>("GET", endpoint),
        refetchInterval: 15000,
    });
    return (
        <Frame
            title="Annotations"
            className="annotations-panel min-h-[120px] max-h-[35vh]"
            bodyClassName="overflow-auto"
        >
            {query.isPending ? (
                <Note>Loading annotations…</Note>
            ) : query.isError ? (
                <Note>Annotations unavailable.</Note>
            ) : !query.data?.length ? (
                <Note>No annotations yet.</Note>
            ) : (
                <table aria-label="Annotations" className="annotations-table">
                    <colgroup>
                        <col style={{ width: "34%" }} />
                        <col style={{ width: "20%" }} />
                        <col style={{ width: "16%" }} />
                        <col style={{ width: "30%" }} />
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col">Annotation</th>
                            <th scope="col">Location</th>
                            <th scope="col">Status</th>
                            <th scope="col">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        {query.data.map((annotation) => (
                            <tr key={annotation.id}>
                                <td className="whitespace-pre-wrap">{annotation.comment}</td>
                                <td className="text-dim">
                                    <div>{annotation.pathname}</div>
                                    <div className="mt-1 text-xs">{annotation.element}</div>
                                </td>
                                <td className="text-dim">
                                    {annotation.status === "cancelled"
                                        ? "Cancelled"
                                        : annotation.status === "resolved"
                                          ? "Done"
                                          : annotation.status === "in_progress"
                                            ? "In progress"
                                            : annotation.delivery === "error"
                                              ? "Delivery failed"
                                              : annotation.delivery === "sent"
                                                ? "Delivered"
                                                : "Queued"}
                                </td>
                                <td>
                                    {annotation.summary ? (
                                        <p className="mt-1">{annotation.summary}</p>
                                    ) : null}
                                    {annotation.syncError ? (
                                        <p className="mt-1 text-warn">{annotation.syncError}</p>
                                    ) : null}
                                    {annotation.delivery === "error" && annotation.threadId ? (
                                        <button
                                            type="button"
                                            className="mt-1 underline"
                                            onClick={() => {
                                                void api(
                                                    "POST",
                                                    `${endpoint}/${annotation.id}/retry`,
                                                ).then(() => query.refetch());
                                            }}
                                        >
                                            Retry delivery
                                        </button>
                                    ) : null}
                                    {!annotation.summary && !annotation.syncError && (
                                        <span className="text-dim">—</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </Frame>
    );
}
