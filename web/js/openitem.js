// Klärfälle im Konto: auflisten und entscheiden. Die Entscheidung trifft immer ein Mensch; Agenten haben dafür keinen Zugang.
import { request } from './api.js';
import { clearChildren, element, formatDate, hideError, showError } from './dom.js';

const KIND_LABELS = { konflikt: 'Widerspruch', nachfragen: 'Nachfrage', beobachten: 'Beobachtung' };

const DECISION_LABELS = {
    bestehend: 'Bisheriger Wert gilt',
    neu: 'Neuer Wert gilt',
    verwerfen: 'Verwerfen',
    erledigt: 'Erledigt',
};

const DECISION_EFFECTS = {
    bestehend: 'Der gespeicherte Wert bleibt, der gemeldete wird verworfen.',
    neu: 'Der gemeldete Wert ersetzt den gespeicherten.',
    verwerfen: 'Der Klärfall wird ohne Änderung an den Daten geschlossen.',
    erledigt: 'Der Klärfall gilt als erledigt.',
};

const CONFIDENCE_LABELS = { bestaetigt: 'bestätigt', wahrscheinlich: 'wahrscheinlich', unsicher: 'unsicher' };

/** „Herkunft, Konfidenz“ in Klammern, soweit bekannt. */
function origin(entry) {
    const parts = [];
    if (entry.source !== null) {
        parts.push(entry.source);
    }
    if (entry.confidence !== null) {
        parts.push(CONFIDENCE_LABELS[entry.confidence] ?? entry.confidence);
    }

    return parts.length === 0 ? '' : ' (' + parts.join(', ') + ')';
}

function comparison(label, entry) {
    return element('p', label + ': ' + entry.value + origin(entry), 'compare');
}

function describeItem(item) {
    const listItem = element('li');
    listItem.appendChild(element('strong', item.vehicle_label ?? 'Ohne Fahrzeug'));
    listItem.appendChild(element('small', (KIND_LABELS[item.kind] ?? item.kind) + ' · ' + formatDate(item.created_at) + ' · gemeldet von ' + item.created_by));
    listItem.appendChild(element('p', item.text));
    if (item.current !== null) {
        listItem.appendChild(comparison('Bisher', item.current));
    }
    if (item.proposed !== null) {
        listItem.appendChild(comparison(item.kind === 'konflikt' ? 'Neu' : 'Vorschlag', item.proposed));
    }

    return listItem;
}

export function setupOpenItems() {
    const list = document.getElementById('open-item-list');
    const empty = document.getElementById('open-item-empty');
    const listError = document.getElementById('open-item-error');

    async function decide(item, decision) {
        const vehicle = item.vehicle_label ?? 'Ohne Fahrzeug';
        if (!window.confirm(vehicle + ': „' + DECISION_LABELS[decision] + '“? ' + DECISION_EFFECTS[decision])) {
            return;
        }
        hideError(listError);
        try {
            await request('POST', 'open-items/' + item.id + '/resolve', { decision });
        } catch (error) {
            showError(listError, error.message);
            await load(false);
            return;
        }
        await load();
    }

    function render(items) {
        clearChildren(list);
        empty.hidden = items.length > 0;
        for (const item of items) {
            const listItem = describeItem(item);
            if (item.can_resolve) {
                const row = element('div', undefined, 'button-row');
                for (const decision of item.decisions) {
                    const button = element('button', DECISION_LABELS[decision]);
                    button.type = 'button';
                    button.setAttribute('aria-label', DECISION_LABELS[decision] + ' — ' + (item.vehicle_label ?? 'Ohne Fahrzeug'));
                    button.addEventListener('click', () => decide(item, decision));
                    row.appendChild(button);
                }
                listItem.appendChild(row);
            } else {
                listItem.appendChild(element('small', 'Nur lesend — lösen kann ein Inhaber oder Mitglied.'));
            }
            list.appendChild(listItem);
        }
    }

    /** `resetError: false` lässt eine eben gezeigte Fehlermeldung stehen, während die Liste neu geladen wird. */
    async function load(resetError = true) {
        if (resetError) {
            hideError(listError);
        }
        try {
            render((await request('GET', 'open-items')).open_items);
        } catch (error) {
            showError(listError, error.message);
        }
    }

    return {
        load,
        clear() {
            hideError(listError);
            clearChildren(list);
            empty.hidden = true;
        },
    };
}
