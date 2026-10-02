import { closestCrossingShadow, getParentElement } from "./dom";

const MEANINGFUL_CLASS = (value: string): boolean =>
    value.length > 2 && !/^[a-z]{1,2}$/.test(value) && !/[A-Z0-9]{5,}/.test(value);

export function getElementPath(target: Element, maxDepth = 4): string {
    const parts: string[] = [];
    let current: Element | null = target;
    let depth = 0;

    while (current && depth < maxDepth) {
        const tag = current.tagName.toLowerCase();

        if (tag === "html" || tag === "body") {
            break;
        }

        let identifier = tag;

        if (typeof current.className === "string" && current.className) {
            const meaningfulClass = current.className
                .split(/\s+/)
                .find((value) => MEANINGFUL_CLASS(value) || value.includes("/"));

            if (meaningfulClass) {
                identifier = `.${meaningfulClass.split("_")[0]}`;
            } else if (current.id) {
                identifier = `#${current.id}`;
            }
        } else if (current.id) {
            identifier = `#${current.id}`;
        }

        const nextParent = getParentElement(current);

        if (!current.parentElement && nextParent) {
            identifier = `⟨shadow⟩ ${identifier}`;
        }

        if (identifier === "div" || identifier === "span") {
            current = nextParent;
            continue;
        }

        parts.unshift(identifier);
        current = nextParent;
        depth += 1;
    }

    return parts.join(" > ");
}

export function identifyElement(target: HTMLElement): { name: string; path: string } {
    const path = getElementPath(target);

    if (target.dataset.element) {
        return { name: target.dataset.element, path };
    }

    const tag = target.tagName.toLowerCase();

    if (["path", "circle", "rect", "line", "g"].includes(tag)) {
        const svg = closestCrossingShadow(target, "svg");
        const parent = svg ? getParentElement(svg) : null;

        if (parent instanceof HTMLElement) {
            return { name: `graphic in ${identifyElement(parent).name}`, path };
        }

        return { name: "graphic element", path };
    }

    if (tag === "svg") {
        const parent = getParentElement(target);

        if (parent?.tagName.toLowerCase() === "button") {
            const buttonText = parent.textContent?.trim();

            return { name: buttonText ? `icon in "${buttonText}" button` : "button icon", path };
        }

        return { name: "icon", path };
    }

    if (tag === "button") {
        const ariaLabel = target.getAttribute("aria-label");

        if (ariaLabel) {
            return { name: `button [${ariaLabel}]`, path };
        }

        const text = target.textContent?.trim();

        return { name: text ? `button "${text.slice(0, 25)}"` : "button", path };
    }

    if (tag === "a") {
        const text = target.textContent?.trim();
        const href = target.getAttribute("href");

        if (text) {
            return { name: `link "${text.slice(0, 25)}"`, path };
        }

        if (href) {
            return { name: `link to ${href.slice(0, 30)}`, path };
        }

        return { name: "link", path };
    }

    if (tag === "input") {
        const type = target.getAttribute("type") || "text";
        const placeholder = target.getAttribute("placeholder");
        const name = target.getAttribute("name");

        if (placeholder) {
            return { name: `input "${placeholder}"`, path };
        }

        if (name) {
            return { name: `input [${name}]`, path };
        }

        return { name: `${type} input`, path };
    }

    if (["h1", "h2", "h3", "h4", "h5", "h6"].includes(tag)) {
        const text = target.textContent?.trim();

        return { name: text ? `${tag} "${text.slice(0, 35)}"` : tag, path };
    }

    if (tag === "p") {
        const text = target.textContent?.trim();

        if (text) {
            return {
                name: `paragraph: "${text.slice(0, 40)}${text.length > 40 ? "..." : ""}"`,
                path,
            };
        }

        return { name: "paragraph", path };
    }

    if (tag === "span" || tag === "label") {
        const text = target.textContent?.trim();

        if (text && text.length < 40) {
            return { name: `"${text}"`, path };
        }

        return { name: tag, path };
    }

    if (tag === "li") {
        const text = target.textContent?.trim();

        if (text && text.length < 40) {
            return { name: `list item: "${text.slice(0, 35)}"`, path };
        }

        return { name: "list item", path };
    }

    if (tag === "blockquote") {
        return { name: "blockquote", path };
    }

    if (tag === "code") {
        const text = target.textContent?.trim();

        if (text && text.length < 30) {
            return { name: `code: \`${text}\``, path };
        }

        return { name: "code", path };
    }

    if (tag === "pre") {
        return { name: "code block", path };
    }

    if (tag === "img") {
        const alt = target.getAttribute("alt");

        return { name: alt ? `image "${alt.slice(0, 30)}"` : "image", path };
    }

    if (tag === "video") {
        return { name: "video", path };
    }

    if (["div", "section", "article", "nav", "header", "footer", "aside", "main"].includes(tag)) {
        const ariaLabel = target.getAttribute("aria-label");
        const role = target.getAttribute("role");

        if (ariaLabel) {
            return { name: `${tag} [${ariaLabel}]`, path };
        }

        if (role) {
            return { name: role, path };
        }

        if (typeof target.className === "string" && target.className) {
            const words = target.className
                .split(/[\s_-]+/)
                .map((value) => value.replace(/[A-Z0-9]{5,}.*$/, ""))
                .filter(MEANINGFUL_CLASS)
                .slice(0, 2);

            if (words.length > 0) {
                return { name: words.join(" "), path };
            }
        }

        return { name: tag === "div" ? "container" : tag, path };
    }

    const text = target.textContent?.trim();

    if (text && text.length < 40) {
        return { name: `"${text}"`, path };
    }

    return { name: tag, path };
}

export function getNearbyText(element: HTMLElement): string {
    const texts: string[] = [];
    const ownText = element.textContent?.trim();

    if (ownText && ownText.length < 100) {
        texts.push(ownText);
    }

    const previous = element.previousElementSibling?.textContent?.trim();

    if (previous && previous.length < 50) {
        texts.unshift(`[before: "${previous.slice(0, 40)}"]`);
    }

    const next = element.nextElementSibling?.textContent?.trim();

    if (next && next.length < 50) {
        texts.push(`[after: "${next.slice(0, 40)}"]`);
    }

    return texts.join(" ");
}

export function getElementClasses(target: HTMLElement): string {
    if (typeof target.className !== "string" || !target.className) {
        return "";
    }

    return target.className
        .split(/\s+/)
        .filter(Boolean)
        .map((value) => {
            const match = value.match(/^([a-zA-Z][a-zA-Z0-9_-]*?)(?:_[a-zA-Z0-9]{5,})?$/);

            return match ? match[1] : value;
        })
        .filter((value, index, list) => list.indexOf(value) === index)
        .join(", ");
}
