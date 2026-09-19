/**
 * Opens a page outside Orbit in a new tab. It clicks a real link and does not call `window.open`:
 * a browser, and a preview pane that embeds one, may turn `window.open` into a popup window or a
 * navigation of this tab, and both always honour a link's `target`.
 */
export function openInNewTab(url: string): void {
    const link = document.createElement("a");

    link.href = url;
    link.target = "_blank";
    link.rel = "noopener noreferrer";
    link.click();
}
