// Kleine Bausteine der Fahrzeug-Seiten: Kennzeichen, Zustandswort, Zahlen, Lade-, Fehler- und Leerzustand.
import { actionButton, element } from './dom.js';

const DUE_STATES = {
    ueberfaellig: { className: 'st bad', label: 'überfällig' },
    bald: { className: 'st warn', label: 'bald fällig' },
    unbekannt: { className: 'st unk', label: 'unbekannt' },
    ok: { className: 'st ok', label: 'in Ordnung' },
};

const DAYS_PER_WEEK = 7;
const DAYS_PER_MONTH = 30.44;
const WEEKS_FROM_DAYS = 31;
const MONTHS_FROM_DAYS = 70;

export const STATUS_LABELS = { aktiv: 'aktiv', verkauft: 'verkauft', stillgelegt: 'stillgelegt', geloescht: 'gelöscht' };

/** Kennzeichen-Schild; ohne Text gibt es kein Schild (`null`). */
export function plate(text, isLarge = false) {
    if (text === null || text === undefined || text === '') {
        return null;
    }
    const sign = element('span', undefined, isLarge ? 'plate lg' : 'plate');
    sign.setAttribute('aria-label', 'Kennzeichen ' + text);
    const europe = element('span', 'D', 'eu');
    europe.setAttribute('aria-hidden', 'true');
    sign.appendChild(europe);
    sign.appendChild(element('span', text, 'num'));

    return sign;
}

/** Abschnitt eines Reiters mit Überschrift; `head` nimmt Knöpfe auf. */
export function section(title, hint) {
    const node = element('div', undefined, 'sec');
    const head = element('div', undefined, 'sec-h');
    head.appendChild(element('h2', title));
    if (hint !== undefined) {
        head.appendChild(element('span', hint, 'muted small'));
    }
    node.appendChild(head);

    return { node, head };
}

/** Wort mit farbigem Punkt (`.st`); die Farbe allein trägt den Zustand nie. */
export function stateWord(className, text) {
    return element('span', text, className);
}

export function dueWord(status) {
    const state = DUE_STATES[status] ?? DUE_STATES.unbekannt;

    return stateWord(state.className, state.label);
}

export function formatKm(value) {
    return value.toLocaleString('de-DE');
}

export function formatEuro(value) {
    return value.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
}

/** Gerundet auf ganze Euro, für geschätzte Werte. */
export function formatWholeEuro(value) {
    return value.toLocaleString('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
}

/** Mittlere Hälfte eines Marktkorridors („11.200–12.500 €“). */
export function formatCorridor(corridor) {
    return Math.round(corridor.low_cents / 100).toLocaleString('de-DE') + '–' + formatWholeEuro(corridor.high_cents / 100);
}

/** Abstand bis zu einem Stichtag in Worten. */
export function remainingText(days) {
    if (days === 0) {
        return 'heute';
    }
    if (days === 1) {
        return 'morgen';
    }
    if (days < WEEKS_FROM_DAYS) {
        return 'in ' + days + ' Tagen';
    }
    if (days < MONTHS_FROM_DAYS) {
        return 'in ' + Math.round(days / DAYS_PER_WEEK) + ' Wochen';
    }

    return 'in ' + Math.round(days / DAYS_PER_MONTH) + ' Monaten';
}

export function skeletons(count = 2) {
    const wrapper = element('div');
    wrapper.setAttribute('aria-hidden', 'true');
    for (let index = 0; index < count; index += 1) {
        wrapper.appendChild(element('div', undefined, 'skel'));
    }

    return wrapper;
}

/** Fehlerblock mit „Neu laden“; `retry` lädt den Bereich erneut. */
export function errorBlock(message, retry) {
    const block = element('div', undefined, 'err');
    block.setAttribute('role', 'alert');
    block.appendChild(element('b', 'Das hat nicht geklappt.'));
    block.appendChild(element('span', message));
    const row = element('div');
    row.appendChild(actionButton('Neu laden', 'btn sm', retry));
    block.appendChild(row);

    return block;
}

/** Leerzustand: sagt, was hierher gehört und was der nächste Schritt ist. `icon` ist ein SVG-String aus `icons.js`. */
export function emptyBlock(icon, headline, text) {
    const block = element('div', undefined, 'empty');
    block.insertAdjacentHTML('beforeend', icon);
    block.appendChild(element('div', headline, 'h'));
    block.appendChild(element('div', text, 'muted small'));

    return block;
}
