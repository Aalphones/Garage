// Inhalt eines Klärfalls (beide Werte, Knöpfe, Notiz) — gleich auf der Seite Klärfälle und aufgeklappt in der Fahrzeug-Übersicht.
// Die Entscheidung trifft immer ein Mensch; sie geht erst nach dem Rückgängig-Fenster an den Server und ist dann endgültig (ADR-010).
import { request } from './api.js';
import { actionButton, element } from './dom.js';
import { openSheet } from './sheet.js';
import { refreshCounters } from './shell.js';
import { toast, UNDO_WINDOW_MS } from './toast.js';
import { badge } from './provenance.js';

const NOTE_MAX = 500;
const READ_ONLY_TEXT = 'Nur Inhaber und Mitglieder können Klärfälle lösen.';

export const KIND_LABELS = { konflikt: 'Widerspruch', nachfragen: 'Nachfrage', beobachten: 'Beobachtung' };

const pendingDecisions = new Set();

// Eine noch nicht gesendete Entscheidung ginge beim Verlassen der Seite verloren.
window.addEventListener('beforeunload', (event) => {
    if (pendingDecisions.size > 0) {
        event.preventDefault();
        event.returnValue = '';
    }
});

function option(label, entry, fallbackValue) {
    const box = element('div', undefined, 'opt');
    box.appendChild(element('span', label, 'lab'));
    box.appendChild(element('span', entry === null ? fallbackValue : entry.value, 'val'));
    if (entry !== null && entry.source !== null && entry.confidence !== null) {
        const row = element('span');
        row.appendChild(badge(entry.source, entry.confidence, { actor: entry.actor, sourceUrl: entry.source_url }));
        box.appendChild(row);
    }

    return box;
}

function noteField(currentNote) {
    const wrapper = element('div', undefined, 'field');
    const label = element('label', 'Notiz (höchstens ' + NOTE_MAX + ' Zeichen)');
    label.htmlFor = 'open-item-note';
    const area = element('textarea');
    area.id = 'open-item-note';
    area.maxLength = NOTE_MAX;
    area.value = currentNote;
    wrapper.appendChild(label);
    wrapper.appendChild(area);

    return { wrapper, area };
}

/**
 * `hooks`: `hide()` blendet den Klärfall aus, sobald entschieden wurde; `show()` bringt ihn bei „Rückgängig“ oder einem Fehler zurück;
 * `done()` meldet, dass der Server die Entscheidung angenommen hat.
 */
export function openItemBody(item, hooks) {
    const root = element('div');
    let note = '';

    if (item.kind === 'konflikt') {
        const pair = element('div', undefined, 'vs');
        pair.appendChild(option('Bisher gespeichert', item.current, 'unbekannt'));
        pair.appendChild(option('Neu gemeldet', item.proposed, 'unbekannt'));
        root.appendChild(pair);
    } else if (item.proposed !== null) {
        const hint = element('p', undefined, 'small');
        hint.appendChild(element('span', 'Vorschlag: ', 'muted'));
        hint.appendChild(document.createTextNode(item.proposed.value));
        root.appendChild(hint);
    }

    if (!item.can_resolve) {
        root.appendChild(element('p', READ_ONLY_TEXT, 'muted small'));

        return root;
    }

    const noteLine = element('p', undefined, 'small muted');
    noteLine.hidden = true;
    const noteButton = actionButton('Notiz', 'btn quiet sm', () => editNote());
    const actions = element('div', undefined, 'acts');

    function decide(decision) {
        const sendLater = setTimeout(send, UNDO_WINDOW_MS);
        pendingDecisions.add(sendLater);
        hooks.hide();
        toast('Entscheidung gespeichert', {
            undo: () => {
                clearTimeout(sendLater);
                pendingDecisions.delete(sendLater);
                hooks.show();
            },
        });

        async function send() {
            const body = { decision };
            if (note !== '') {
                body.note = note;
            }
            try {
                await request('POST', 'open-items/' + item.id + '/resolve', body);
            } catch (error) {
                pendingDecisions.delete(sendLater);
                hooks.show();
                toast('Die Entscheidung konnte nicht gespeichert werden: ' + error.message);

                return;
            }
            pendingDecisions.delete(sendLater);
            hooks.done();
            refreshCounters();
        }
    }

    function editNote() {
        const field = noteField(note);
        openSheet({
            title: 'Notiz zum Klärfall',
            body: field.wrapper,
            actions: [
                { label: 'Abbrechen', role: 'cancel' },
                {
                    label: 'Übernehmen',
                    role: 'primary',
                    onClick: () => {
                        note = field.area.value.trim();
                        noteLine.textContent = 'Notiz: ' + note;
                        noteLine.hidden = note === '';
                        noteButton.textContent = note === '' ? 'Notiz' : 'Notiz ändern';
                    },
                },
            ],
        });
    }

    function decisionButton(label, decision, isPrimary) {
        return actionButton(label, isPrimary ? 'btn sm primary' : 'btn sm', () => decide(decision));
    }

    if (item.kind === 'konflikt') {
        actions.appendChild(decisionButton((item.current?.value ?? 'Bisheriger Wert') + ' gilt', 'bestehend', true));
        actions.appendChild(decisionButton((item.proposed?.value ?? 'Neuer Wert') + ' gilt', 'neu', true));
        actions.appendChild(decisionButton('Beide falsch', 'verwerfen', false));
    } else {
        actions.appendChild(decisionButton('Erledigt', 'erledigt', true));
        actions.appendChild(decisionButton('Verwerfen', 'verwerfen', false));
    }
    actions.appendChild(noteButton);
    root.appendChild(noteLine);
    root.appendChild(actions);

    return root;
}
