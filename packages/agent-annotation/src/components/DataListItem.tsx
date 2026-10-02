export default function DataListItem({
    label,
    value,
    title,
}: {
    label: string;
    value: string;
    title?: string;
    truncate?: string;
}) {
    return (
        <div className="flex gap-2 px-2 py-1.5">
            <dt className="shrink-0 text-white/50">{label}</dt>
            <dd
                className="min-w-0 flex-1 overflow-hidden text-ellipsis whitespace-nowrap text-right text-white"
                title={title}
            >
                {value}
            </dd>
        </div>
    );
}
