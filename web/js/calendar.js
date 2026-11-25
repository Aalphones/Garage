// Kalender-Abo im Konto: je Garage der geheime Abo-Link zum Kopieren; Inhaber erzeugen ihn neu (ADR-024).
import { ApiError, request } from './api.js';
import { actionButton, clearChildren, element } from './dom.js';
import { tipText } from './provenance.js';
import { openSheet } from './sheet.js';
import { toast } from './toast.js';

const OWNER_ROLE = 'inhaber';
const PATH_MARK = '/kalender/';
const SLUG_START_CHARS = 4;
const SLUG_END_CHARS = 2;
const SUFFIX = '.ics';
const CONTENT_TEXT = 'Fälligkeiten, Kündigungsstichtage, Plaketten, Garantieenden aller aktiven Autos · dein Kalender fragt selbst ab';
const WHO_TEXT = 'Wer den Link hat, sieht die Termine — ohne Anmeldung. Gib ihn nur in deinen eigenen Kalender. „Neuen Link erzeugen“ macht den alten sofort ungültig.';
const ROTATE_TEXT = 'Der alte Link hört sofort auf zu funktionieren. Im Kalender neu abonnieren.';

function failureText(error, fallback) {
    return error instanceof ApiError ? error.message : fallback;
}

/** Anfang und Ende der Signatur reichen zum Wiedererkennen; die volle Adresse gibt es nur über „Link kopieren“. */
function shortened(url) {
    const slugStart = url.indexOf(PATH_MARK) + PATH_MARK.length;
    const slug = url.slice(slugStart, -SUFFIX.length);

    return url.slice(0, slugStart) + slug.slice(0, SLUG_START_CHARS) + '…' + slug.slice(-SLUG_END_CHARS) + SUFFIX;
}

/** Ohne Zugriff auf die Zwischenablage steht die volle Adresse markiert im Blatt. */
function showForManualCopy(url) {
    const body = element('div');
    body.appendChild(element('p', 'Kopieren ging nicht. Markiere die Adresse und kopiere sie selbst.'));
    const field = element('input');
    field.type = 'text';
    field.readOnly = true;
    field.value = url;
    field.setAttribute('aria-label', 'Kalender-Link');
    body.appendChild(field);
    openSheet({ title: 'Kalender-Link', body, actions: [{ label: 'Schließen', role: 'cancel' }] });
    field.select();
}

async function copyLink(url) {
    try {
        await navigator.clipboard.writeText(url);
    } catch {
        showForManualCopy(url);

        return;
    }
    toast('Kalender-Link kopiert');
}

function openRotateSheet(garage, onRotated) {
    const body = element('div');
    body.appendChild(element('p', ROTATE_TEXT));
    const failure = element('p', undefined, 'error');
    failure.setAttribute('role', 'alert');
    failure.hidden = true;
    body.appendChild(failure);

    const rotate = async () => {
        let url;
        try {
            url = (await request('POST', 'garages/' + garage.id + '/calendar-link/rotate', {})).url;
        } catch (error) {
            failure.textContent = failureText(error, 'Der neue Link konnte nicht erzeugt werden.');
            failure.hidden = false;

            return false;
        }
        onRotated(url);
        toast('Neuer Kalender-Link erzeugt');

        return true;
    };
    openSheet({
        title: 'Neuen Link erzeugen',
        body,
        actions: [{ label: 'Abbrechen', role: 'cancel' }, { label: 'Neuen Link erzeugen', role: 'primary', onClick: rotate }],
    });
}

function calendarRow(garage) {
    const row = element('div', undefined, 'row cal-row');
    const actions = element('span', undefined, 'r cal-actions');
    const details = element('span', undefined, 's');
    row.append(element('span', garage.name, 't'), actions, details);
    let url = null;

    const address = element('span', 'Link wird geladen …', 'cal-link');
    const copy = actionButton('Link kopieren', 'btn sm primary', () => copyLink(url));
    copy.disabled = true;
    copy.setAttribute('aria-label', 'Kalender-Link von ' + garage.name + ' kopieren');
    actions.appendChild(copy);

    const show = (nextUrl) => {
        url = nextUrl;
        address.textContent = shortened(nextUrl);
        copy.disabled = false;
    };
    if (garage.role === OWNER_ROLE) {
        const renew = actionButton('Neuen Link erzeugen', 'btn sm', () => openRotateSheet(garage, show));
        renew.setAttribute('aria-label', 'Neuen Kalender-Link für ' + garage.name + ' erzeugen');
        actions.appendChild(renew);
    }
    const who = actionButton('Wer sieht das?', 'linkbtn', () => tipText(who, 'Wer sieht das?', WHO_TEXT));
    details.append(address, element('span', CONTENT_TEXT), who);

    const load = async () => {
        try {
            show((await request('GET', 'garages/' + garage.id + '/calendar-link')).url);
        } catch (error) {
            address.textContent = failureText(error, 'Der Link konnte nicht geladen werden.') + ' Lade die Seite neu.';
        }
    };

    return { node: row, load };
}

export function setupCalendar() {
    const list = document.getElementById('calendar-list');
    const empty = document.getElementById('calendar-empty');

    return {
        async load(garages) {
            clearChildren(list);
            list.hidden = garages.length === 0;
            empty.hidden = garages.length > 0;
            const rows = garages.map(calendarRow);
            list.append(...rows.map((row) => row.node));
            await Promise.all(rows.map((row) => row.load()));
        },
        clear() {
            clearChildren(list);
        },
    };
}
