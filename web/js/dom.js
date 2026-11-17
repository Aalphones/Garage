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

/** Ein `<button type="button">`; `className` darf leer sein. */
export function actionButton(text, className, onClick) {
    const button = element('button', text, className === '' ? undefined : className);
    button.type = 'button';
    button.addEventListener('click', onClick);

    return button;
}

export function link(text, href, className) {
    const anchor = element('a', text, className);
    anchor.href = href;

    return anchor;
}

/** Zeitstempel der API: `YYYY-MM-DD HH:MM:SS` (UTC) oder ISO mit `T` und `Z`. */
function parseMoment(value) {
    if (value.includes('T')) {
        return new Date(value);
    }

    return new Date(value.replace(' ', 'T') + 'Z');
}

/** Datum und Uhrzeit in der Zeitzone des Geräts. */
export function formatMoment(value) {
    return parseMoment(value).toLocaleString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function formatClock(value) {
    return parseMoment(value).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
}

/** Kalendertag: `YYYY-MM-DD` bleibt der Tag, den die Quelle nennt; ein Zeitstempel wird in die Gerätezeit gerechnet. */
export function formatDay(value) {
    if (value.length === 10) {
        return new Date(value + 'T00:00:00Z').toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' });
    }

    return parseMoment(value).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

export function formatBytes(bytes) {
    if (bytes >= 1048576) {
        return (bytes / 1048576).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' MB';
    }

    return Math.max(1, Math.round(bytes / 1024)).toLocaleString('de-DE') + ' KB';
}
