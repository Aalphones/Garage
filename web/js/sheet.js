// Blatt von unten (unter 768 px) bzw. Dialog: Fokus hinein, gefangen, beim Schließen zurück; Escape schließt.
import { actionButton, element } from './dom.js';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
const TITLE_ID = 'sheet-title';

/**
 * `body`: DOM-Knoten. `actions`: `[{ label, role?: 'primary'|'danger'|'cancel', onClick }]`;
 * `onClick` gibt `false` zurück, um das Blatt offen zu lassen (etwa bei einem Eingabefehler).
 * Gibt `{ close }` zurück.
 */
export function openSheet({ title, body, actions = [] }) {
    const previouslyFocused = document.activeElement;
    const scrim = element('div', undefined, 'scrim');
    const sheet = element('div', undefined, 'sheet');
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');
    sheet.setAttribute('aria-labelledby', TITLE_ID);

    sheet.appendChild(element('div', undefined, 'grab'));
    const heading = element('h2', title);
    heading.id = TITLE_ID;
    sheet.appendChild(heading);
    sheet.appendChild(body);

    function close() {
        scrim.remove();
        document.removeEventListener('keydown', onKeydown, true);
        if (previouslyFocused instanceof HTMLElement) {
            previouslyFocused.focus();
        }
    }

    function trapFocus(event) {
        const focusable = [...sheet.querySelectorAll(FOCUSABLE)];
        if (focusable.length === 0) {
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
        } else if (event.key === 'Tab') {
            trapFocus(event);
        }
    }

    if (actions.length > 0) {
        const foot = element('div', undefined, 'foot');
        for (const action of actions) {
            const isPlain = action.role === undefined || action.role === 'cancel';
            const className = isPlain ? 'btn' : 'btn ' + action.role;
            foot.appendChild(actionButton(action.label, className, async () => {
                const result = await action.onClick?.();
                if (result !== false) {
                    close();
                }
            }));
        }
        sheet.appendChild(foot);
    }

    scrim.addEventListener('click', (event) => {
        if (event.target === scrim) {
            close();
        }
    });
    document.addEventListener('keydown', onKeydown, true);
    scrim.appendChild(sheet);
    document.body.appendChild(scrim);
    const firstField = sheet.querySelector('input, select, textarea') ?? sheet.querySelector('button');
    firstField?.focus();

    return { close };
}
