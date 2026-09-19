import { toJpeg } from "html-to-image";
import type { AnnotationDraft, AnnotationRect } from "@/annotation/types";
import { screenshotCrop } from "@/annotation/viewport";

const HIGHLIGHT = "#F53003";

export async function captureAnnotationScreenshot(
    draft: AnnotationDraft,
): Promise<string | undefined> {
    if (typeof document === "undefined" || !draft.boundingBox) {
        return undefined;
    }

    const crop = screenshotCrop(draft.boundingBox, {
        viewportWidth: window.innerWidth,
        viewportHeight: window.innerHeight,
    });

    try {
        const image = await toJpeg(document.documentElement, {
            quality: 0.72,
            pixelRatio: 1,
            width: crop.width,
            height: crop.height,
            canvasWidth: crop.width,
            canvasHeight: crop.height,
            cacheBust: true,
            filter: (node) => !(node instanceof Element) || !shouldHideFromCapture(node),
            style: {
                transform: `translate(${-(window.scrollX + crop.x)}px, ${-(window.scrollY + crop.y)}px)`,
                transformOrigin: "top left",
            },
        });

        return await paintHighlight(image, crop, draft.boundingBox);
    } catch {
        return undefined;
    }
}

export async function paintHighlight(
    image: string,
    crop: AnnotationRect,
    highlight: AnnotationRect,
    color = HIGHLIGHT,
): Promise<string> {
    const picture = await loadImage(image);
    const canvas = document.createElement("canvas");
    canvas.width = crop.width;
    canvas.height = crop.height;

    const context = canvas.getContext("2d");

    if (!context) {
        return image;
    }

    context.drawImage(picture, 0, 0, crop.width, crop.height);
    context.fillStyle = `${color}14`;
    context.strokeStyle = `${color}80`;
    context.lineWidth = 2;
    const left = highlight.x - crop.x;
    const top = highlight.y - crop.y;

    context.beginPath();

    if (typeof context.roundRect === "function") {
        context.roundRect(left, top, highlight.width, highlight.height, 4);
    } else {
        context.rect(left, top, highlight.width, highlight.height);
    }

    context.fill();
    context.stroke();

    return canvas.toDataURL("image/jpeg", 0.72);
}

function shouldHideFromCapture(node: Element): boolean {
    return Boolean(
        node.closest("#laravel-toolbar-shadow-host") ||
        node.closest("#laravel-toolbar-annotation-host"),
    );
}

function loadImage(src: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error("Could not load annotation screenshot."));
        image.src = src;
    });
}
