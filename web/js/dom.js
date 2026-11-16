// Kleine Helfer zum Bauen der Oberfläche. Text geht immer über textContent, nie über innerHTML.
export function element(tagName, text, className) {
    const node = document.createElement(tagName);
    if (text !== undefined) {
        node.textContent = text;
    }
    if (className !== undefined) {
        node.className = className;
    }

    return node;
}

export function clearChildren(node) {
    while (node.firstChild !== null) {
        node.removeChild(node.firstChild);
    }
}

/** Zeigt eine Fehlermeldung und setzt den Fokus darauf, damit Screenreader sie vorlesen und Tastaturnutzer sie finden. */
export function showError(node, message) {
    node.textContent = message;
    node.hidden = false;
    node.focus();
}

export function hideError(node) {
    node.textContent = '';
    node.hidden = true;
}

/** Die API liefert `YYYY-MM-DD HH:MM:SS` in UTC. */
export function formatDate(value) {
    const date = new Date(value.replace(' ', 'T') + 'Z');

    return date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
