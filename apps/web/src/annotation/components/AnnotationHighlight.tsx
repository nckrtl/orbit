import { useAnnotationAccent } from "@/annotation/accent";

type AnnotationHighlightProps = {
    rect: { x: number; y: number; width: number; height: number };
    strong?: boolean;
};

export default function AnnotationHighlight({ rect, strong = false }: AnnotationHighlightProps) {
    const { color } = useAnnotationAccent();

    return (
        <div
            data-annotation-highlight
            className="pointer-events-none absolute box-border rounded"
            style={{
                left: `${rect.x}px`,
                top: `${rect.y}px`,
                width: `${rect.width}px`,
                height: `${rect.height}px`,
                border: `2px solid color-mix(in srgb, ${color} 50%, transparent)`,
                backgroundColor: strong
                    ? `color-mix(in srgb, ${color} 6%, transparent)`
                    : `color-mix(in srgb, ${color} 4%, transparent)`,
            }}
        />
    );
}
