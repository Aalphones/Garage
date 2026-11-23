// Umschalter „Ansicht“ in der Werkzeugleiste der Reiter; die Wahl bleibt je Gerät in `localStorage`.
import { actionButton, element } from './dom.js';

const NARROW_QUERY = '(max-width: 767px)';

export function isNarrow() {
    return window.matchMedia(NARROW_QUERY).matches;
}

/** Gespeicherte Wahl, sonst `fallback`; ein fremder Wert im Speicher gilt nicht. */
export function readView(storageKey, allowed, fallback) {
    let stored = null;
    try {
        stored = localStorage.getItem(storageKey);
    } catch {
        stored = null;
    }

    return allowed.includes(stored) ? stored : fallback;
}

function storeView(storageKey, value) {
    try {
        localStorage.setItem(storageKey, value);
    } catch {
        // Ohne Speicher gilt die Wahl nur bis zum Neuladen.
    }
}

/**
 * Gruppe aus zwei Knöpfen. `options`: `[{ value, label, icon }]`; `onChange(value)` baut die Ansicht neu.
 */
export function viewSwitch(storageKey, options, current, onChange) {
    const group = element('div', undefined, 'seg');
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', 'Ansicht');
    for (const option of options) {
        const button = actionButton('', '', () => {
            storeView(storageKey, option.value);
            onChange(option.value);
        });
        button.insertAdjacentHTML('beforeend', option.icon);
        button.appendChild(document.createTextNode(option.label));
        button.setAttribute('aria-pressed', String(option.value === current));
        group.appendChild(button);
    }

    return group;
}
