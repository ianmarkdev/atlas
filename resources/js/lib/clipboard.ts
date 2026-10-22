/**
 * Copy text to the clipboard. `navigator.clipboard` exists only on HTTPS and localhost, so on a
 * plain-HTTP host (e.g. a local `*.test` site) this falls back to a hidden textarea and the
 * older copy command. Resolves to whether the text was copied.
 */
export async function copyToClipboard(text: string): Promise<boolean> {
    if (window.isSecureContext && navigator.clipboard) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            // Denied permission or lost focus: try the fallback.
        }
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.top = '0';
    textarea.style.left = '0';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        document.body.removeChild(textarea);
    }
}
