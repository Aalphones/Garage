// Eine Leiste unten für Rückmeldung und „Rückgängig“. Sie trägt role="status", damit Screenreader den Text vorlesen.
import { actionButton, element } from './dom.js';

/** So lange läuft die Rückgängig-Leiste; eine verzögerte Entscheidung wird erst danach gesendet. */
export const UNDO_WINDOW_MS = 6000;

let current = null;
let timer = null;

export function hideToast() {
    clearTimeout(timer);
    if (current !== null) {
        current.remove();
        current = null;
    }
}

/** `undo` darf fehlen: dann ist die Leiste nur eine Rückmeldung. */
export function toast(text, { undo } = {}) {
    hideToast();
    const bar = element('div', undefined, 'toast');
    bar.setAttribute('role', 'status');
    bar.appendChild(element('span', text));
    if (undo !== undefined) {
        bar.appendChild(actionButton('Rückgängig', '', () => {
            hideToast();
            undo();
        }));
    }
    document.body.appendChild(bar);
    current = bar;
    timer = setTimeout(hideToast, UNDO_WINDOW_MS);
}
