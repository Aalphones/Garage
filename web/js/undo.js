// Leiste mit „Rückgängig“ für 6 Sekunden. Der Platzhalter `slot` trägt `role="status"`, damit Screenreader den Text vorlesen.
import { actionButton, clearChildren, element } from './dom.js';

const UNDO_WINDOW_MS = 6000;

export function createUndoBar(slot) {
    let timer = null;

    function hide() {
        clearTimeout(timer);
        clearChildren(slot);
    }

    /** `onUndo` darf fehlen: dann ist die Leiste nur eine Rückmeldung. */
    function show(text, onUndo) {
        hide();
        const bar = element('div', undefined, 'toast');
        bar.appendChild(element('span', text));
        if (onUndo !== undefined) {
            bar.appendChild(actionButton('Rückgängig', '', () => {
                hide();
                onUndo();
            }));
        }
        slot.appendChild(bar);
        timer = setTimeout(hide, UNDO_WINDOW_MS);
    }

    return { show, hide };
}
