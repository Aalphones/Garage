// Einstieg für eingang.html: neuen Eintrag anlegen und die Liste des Eingangs zeigen.
import { request } from './api.js';
import { setupCapture } from './capture.js';
import { actionButton, clearChildren, element, formatClock, formatMoment, hideError, link, showError } from './dom.js';
import { loadContext, requireSession } from './session.js';
import { createUndoBar } from './undo.js';

const statusLine = document.getElementById('status');
const content = document.getElementById('content');
const tipButton = document.getElementById('tip-button');
const tipText = document.getElementById('tip-eingang');
const captureStart = document.getElementById('capture-start');
const readonlyNote = document.getElementById('readonly-note');
const inboxList = document.getElementById('inbox-list');
const inboxEmpty = document.getElementById('inbox-empty');
const inboxError = document.getElementById('inbox-error');
const undoBar = createUndoBar(document.getElementById('undo-slot'));

let context = null;

function vehicleLabel(vehicleId) {
    const vehicle = context.vehicles.find((candidate) => candidate.id === vehicleId);
    if (vehicle === undefined) {
        return 'Fahrzeug offen';
    }

    return vehicle.label;
}

function pageWord(count) {
    if (count === 1) {
        return '1 Seite';
    }

    return count + ' Seiten';
}

/** Zustand als Wort mit Punkt; der Status kommt fertig vom Server (abgelaufene Reservierungen stehen dort schon als „neu“). */
function describeState(item) {
    const wrapper = element('div');
    if (item.status === 'in_arbeit') {
        wrapper.appendChild(element('span', 'In Arbeit (' + item.claimed_by + ', reserviert bis ' + formatClock(item.claimed_until) + ')', 'state'));
    } else if (item.status === 'erfasst') {
        wrapper.appendChild(element('span', 'Erfasst: ' + item.result, 'state done'));
    } else if (item.status === 'klaerfall') {
        wrapper.appendChild(element('span', 'Klärfall: ' + item.result, 'state warn'));
        wrapper.appendChild(document.createTextNode(' '));
        wrapper.appendChild(link('Ansehen', '/garage/index.html#klaerfaelle'));
    } else if (item.status === 'verworfen') {
        wrapper.appendChild(element('span', 'Verworfen: ' + (item.result ?? 'ohne Angabe'), 'state'));
    } else {
        wrapper.appendChild(element('span', 'Neu – wartet auf einen Agenten', 'state'));
    }

    return wrapper;
}

function describeThumb(item) {
    const thumb = element('div', undefined, 'thumb');
    thumb.setAttribute('aria-hidden', 'true');
    const firstWithPreview = item.documents.find((document) => document.preview_url !== null);
    if (firstWithPreview !== undefined) {
        const image = element('img');
        image.alt = '';
        image.loading = 'lazy';
        image.src = firstWithPreview.preview_url;
        thumb.appendChild(image);
    }

    return thumb;
}

async function discardItem(item) {
    hideError(inboxError);
    try {
        await request('POST', 'inbox/' + item.id + '/discard');
    } catch (error) {
        showError(inboxError, error.message);
        await loadInbox(false);
        return;
    }
    await loadInbox();
    undoBar.show('Eintrag verworfen.', async () => {
        try {
            await request('POST', 'inbox/' + item.id + '/restore');
        } catch (error) {
            showError(inboxError, error.message);
        }
        await loadInbox(false);
    });
}

function describeItem(item) {
    const listItem = element('li');
    const row = element('div', undefined, 'row');
    row.appendChild(describeThumb(item));

    const text = element('div');
    let note = item.note;
    if (note === '') {
        note = 'Ohne Notiz';
    }
    const heading = element('div');
    heading.appendChild(element('strong', note));
    heading.appendChild(document.createTextNode(' · ' + vehicleLabel(item.vehicle_id)));
    text.appendChild(heading);
    text.appendChild(element('small', formatMoment(item.created_at) + ' · ' + pageWord(item.page_count)));
    text.appendChild(describeState(item));
    if (item.status === 'neu' && item.can_edit) {
        const discard = actionButton('Eintrag verwerfen', 'link-button', () => discardItem(item));
        discard.setAttribute('aria-label', 'Eintrag verwerfen: ' + note);
        text.appendChild(discard);
    }
    row.appendChild(text);
    listItem.appendChild(row);

    return listItem;
}

/** `resetError: false` lässt eine eben gezeigte Fehlermeldung stehen, während die Liste neu geladen wird. */
async function loadInbox(resetError = true) {
    if (resetError) {
        hideError(inboxError);
    }
    try {
        const items = (await request('GET', 'inbox')).items;
        clearChildren(inboxList);
        inboxEmpty.hidden = items.length > 0;
        items.forEach((item) => inboxList.appendChild(describeItem(item)));
    } catch (error) {
        showError(inboxError, error.message);
    }
}

tipButton.addEventListener('click', () => {
    tipText.hidden = !tipText.hidden;
    tipButton.setAttribute('aria-expanded', String(!tipText.hidden));
});

async function start() {
    const user = await requireSession(statusLine);
    if (user === null) {
        return;
    }
    try {
        context = await loadContext();
    } catch (error) {
        statusLine.textContent = error.message;
        return;
    }
    content.hidden = false;
    if (context.writableGarages.length > 0) {
        setupCapture(context, undoBar, loadInbox);
    } else {
        captureStart.hidden = true;
        readonlyNote.hidden = false;
    }
    await loadInbox();
}

start();
