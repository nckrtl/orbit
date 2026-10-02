import { annotationMetadataDisplayValue, annotationMetadataRows } from "../payload";
import DataList from "./DataList";
import DataListItem from "./DataListItem";
import type { AnnotationDraft } from "../types";

export default function AnnotationMetadata({ draft }: { draft: AnnotationDraft }) {
    const rows = annotationMetadataRows(draft);

    if (rows.length === 0) {
        return null;
    }

    return (
        <div
            data-annotation-metadata
            className="mt-2 overflow-hidden rounded-xl border border-white/10 bg-[#111111]/95 shadow-none backdrop-blur-xl"
        >
            <DataList>
                {rows.map((row) => (
                    <DataListItem
                        key={row.key}
                        label={row.key}
                        title={row.value}
                        truncate="start"
                        value={annotationMetadataDisplayValue(row.key, row.value)}
                    />
                ))}
            </DataList>
        </div>
    );
}
