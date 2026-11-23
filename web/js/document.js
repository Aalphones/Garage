// Einstieg für belege.html: Liste, Galerie und Detail (`#beleg/<id>`) der Belege.
import { request, upload } from './api.js';
import { buildUploadForm, prepareFile } from './capture.js';
import { actionButton, clearChildren, element, hideError, link, showError } from './dom.js';
import { attachedWords, documentDate, documentTitle, renderDocuments, TYPE_LABELS } from './document-list.js';
import { loadContext, requireSession } from './session.js';
import { registerShareWorker } from './share.js';
import { mountShell } from './shell.js';
import { hideToast, toast } from './toast.js';

registerShareWorker();

const DETAIL_HASH = /^#beleg\/(\d+)$/;
const DISCARD_REASON = 'In der Oberfläche verworfen';

const CHANNEL_LABELS = {
    web: 'Browser',
    ticket: 'Upload-Ticket',
    widget: 'Claude-Widget',
    chatgpt: 'ChatGPT',
    url: 'Link-Import',
};

const statusLine = document.getElementById('status');
const backLink = document.getElementById('back-link');
const pageTitle = document.getElementById('page-title');
const listView = document.getElementById('list-view');
const detailView = document.getElementById('detail-view');
const listBody = document.getElementById('list-body');
const detailBody = document.getElementById('detail-body');
const detailError = document.getElementById('detail-error');
const chipsGroup = document.getElementById('vehicle-chips');

let context = null;
let vehicleFilter = null;
let current = null;
let pageIndex = 0;

function vehicleName(vehicleId) {
    const vehicle = context.vehicles.find((candidate) => candidate.id === vehicleId);
    if (vehicle === undefined) {
        return 'Kein Fahrzeug';
    }

    return vehicle.label;
}

function showDocuments() {
    return renderDocuments(listBody, { vehicleId: vehicleFilter, vehicles: context.vehicles });
}

function renderChips() {
    clearChildren(chipsGroup);
    const choices = [{ id: null, label: 'Alle' }, ...context.vehicles.map((vehicle) => ({ id: vehicle.id, label: vehicle.label }))];
    for (const choice of choices) {
        const chip = actionButton(choice.label, '', () => {
            vehicleFilter = choice.id;
            chipsGroup.querySelectorAll('button').forEach((other) => other.setAttribute('aria-pressed', String(other === chip)));
            showDocuments();
        });
        chip.setAttribute('aria-pressed', String(choice.id === vehicleFilter));
        chipsGroup.appendChild(chip);
    }
}

function showList() {
    detailView.hidden = true;
    backLink.hidden = true;
    pageTitle.textContent = 'GARAGE';
    window.document.title = 'Belege – GARAGE';
    listView.hidden = false;
    renderChips();
    showDocuments();
}

// --- Detail ---------------------------------------------------------------------------------------------------

function labeledField(labelText, fieldId, control) {
    const wrapper = document.createDocumentFragment();
    const term = element('dt');
    const label = element('label', labelText, 'field-label');
    label.htmlFor = fieldId;
    term.appendChild(label);
    const definition = element('dd');
    definition.appendChild(control);
    wrapper.append(term, definition);

    return wrapper;
}

function plainField(labelText, value) {
    const wrapper = document.createDocumentFragment();
    wrapper.appendChild(element('dt', labelText));
    wrapper.appendChild(element('dd', value));

    return wrapper;
}

function describePreview(entry) {
    const fragment = document.createDocumentFragment();
    if (entry.pages.length === 0) {
        fragment.appendChild(element('p', 'Zu diesem PDF gibt es keine Seitenbilder. Öffne das Original, um es zu lesen.', 'note'));
        return fragment;
    }

    const frame = element('div', undefined, 'preview');
    const image = element('img');
    image.alt = 'Seite ' + (pageIndex + 1) + ' von ' + entry.pages.length + ': ' + documentTitle(entry);
    image.src = entry.pages[pageIndex];
    frame.appendChild(image);
    fragment.appendChild(frame);

    if (entry.pages.length > 1) {
        const navigation = element('div', undefined, 'preview-nav');
        const previous = actionButton('← Seite', '', () => showPage(pageIndex - 1));
        previous.disabled = pageIndex === 0;
        const next = actionButton('Seite →', '', () => showPage(pageIndex + 1));
        next.disabled = pageIndex === entry.pages.length - 1;
        navigation.append(previous, element('span', 'Seite ' + (pageIndex + 1) + ' von ' + entry.pages.length), next);
        fragment.appendChild(navigation);
    }

    return fragment;
}

function showPage(index) {
    pageIndex = index;
    renderDetail(current);
}

function describeOriginalButton(entry) {
    let text = 'Foto in voller Größe';
    if (entry.mime === 'application/pdf') {
        text = 'Original öffnen (PDF)';
    }
    const anchor = link(text, entry.original_url, 'button');
    anchor.target = '_blank';
    anchor.rel = 'noopener';
    anchor.title = 'Öffnet über einen Link, der 10 Minuten gilt';
    const row = element('div', undefined, 'button-row');
    row.appendChild(anchor);

    return row;
}

function describeMissingOriginal(entry) {
    const fragment = document.createDocumentFragment();
    const message = element('p', 'Original fehlt. Die Daten sind erfasst, die Datei kam im Chat nicht an.', 'error');
    fragment.appendChild(message);
    if (!entry.can_edit) {
        return fragment;
    }

    const zone = element('div', undefined, 'dropzone');
    zone.appendChild(element('strong', 'Original nachreichen'));
    zone.appendChild(element('small', 'Foto oder PDF dieses Belegs'));
    const input = element('input');
    input.type = 'file';
    input.accept = 'image/*,application/pdf';
    input.hidden = true;
    input.addEventListener('change', async () => {
        if (input.files.length > 0) {
            await submitLateFile(input.files[0]);
        }
        input.value = '';
    });
    const pick = actionButton('Datei wählen', 'button primary', () => input.click());
    pick.id = 'late-pick';
    const progress = element('p');
    progress.id = 'late-status';
    progress.setAttribute('role', 'status');
    zone.append(pick, input, progress);
    fragment.appendChild(zone);

    return fragment;
}

async function submitLateFile(file) {
    const statusNode = document.getElementById('late-status');
    hideError(detailError);
    try {
        const prepared = await prepareFile(file, (step) => {
            statusNode.textContent = step;
        });
        statusNode.textContent = 'Lade hoch … 0 %';
        await upload('documents/' + current.id + '/file', buildUploadForm(prepared), (fraction) => {
            statusNode.textContent = 'Lade hoch … ' + Math.round(fraction * 100) + ' %';
        });
        current = await request('GET', 'documents/' + current.id);
    } catch (error) {
        statusNode.textContent = '';
        showError(detailError, 'Hochladen fehlgeschlagen: ' + error.message);
        return;
    }
    pageIndex = 0;
    renderDetail(current);
    toast('Original abgelegt.');
}

function typeControl(entry) {
    const select = element('select');
    select.id = 'field-type';
    for (const [value, label] of Object.entries(TYPE_LABELS)) {
        const option = element('option', label);
        option.value = value;
        select.appendChild(option);
    }
    select.value = entry.doc_type;
    select.addEventListener('change', () => saveField('doc_type', select, current.doc_type));

    return select;
}

function vehicleControl(entry) {
    const select = element('select');
    select.id = 'field-vehicle';
    if (entry.vehicle_id === null) {
        const none = element('option', 'Kein Fahrzeug');
        none.value = '';
        select.appendChild(none);
    }
    for (const vehicle of context.vehicles.filter((candidate) => candidate.garageId === entry.garage_id)) {
        const option = element('option', vehicle.label);
        option.value = String(vehicle.id);
        select.appendChild(option);
    }
    select.value = entry.vehicle_id === null ? '' : String(entry.vehicle_id);
    select.addEventListener('change', () => saveField('vehicle_id', select, current.vehicle_id));

    return select;
}

function fieldValue(field, select) {
    if (field === 'vehicle_id') {
        return select.value === '' ? null : Number(select.value);
    }

    return select.value;
}

/** Speichert die Auswahl sofort; „Rückgängig“ setzt den alten Wert zurück. */
async function saveField(field, select, previous) {
    hideError(detailError);
    try {
        current = await request('PATCH', 'documents/' + current.id, { [field]: fieldValue(field, select) });
    } catch (error) {
        select.value = previous === null ? '' : String(previous);
        showError(detailError, error.message);
        return;
    }
    const savedId = current.id;
    toast('Gespeichert.', {
        undo: async () => {
            try {
                current = await request('PATCH', 'documents/' + savedId, { [field]: previous });
                renderDetail(current);
            } catch (error) {
                showError(detailError, error.message);
            }
        },
    });
}

async function discardCurrent() {
    hideError(detailError);
    const discardedId = current.id;
    try {
        current = await request('POST', 'documents/' + discardedId + '/discard', { reason: DISCARD_REASON });
    } catch (error) {
        showError(detailError, error.message);
        return;
    }
    renderDetail(current);
    toast('Beleg verworfen.', { undo: () => restoreDocument(discardedId) });
}

async function restoreDocument(documentId) {
    hideError(detailError);
    try {
        current = await request('POST', 'documents/' + documentId + '/restore');
    } catch (error) {
        showError(detailError, error.message);
        return;
    }
    renderDetail(current);
}

function describeFacts(entry) {
    const facts = element('dl', undefined, 'details');
    if (entry.can_edit) {
        facts.appendChild(labeledField('Typ', 'field-type', typeControl(entry)));
        facts.appendChild(labeledField('Fahrzeug', 'field-vehicle', vehicleControl(entry)));
    } else {
        facts.appendChild(plainField('Typ', TYPE_LABELS[entry.doc_type] ?? entry.doc_type));
        facts.appendChild(plainField('Fahrzeug', vehicleName(entry.vehicle_id)));
    }
    facts.appendChild(plainField('Datum', documentDate(entry)));
    let attached = 'Noch an nichts – ein Agent ordnet zu.';
    if (entry.attachments.length > 0) {
        attached = attachedWords(entry.attachments);
    }
    facts.appendChild(plainField('Hängt an', attached));
    facts.appendChild(plainField('Abgelegt', 'von ' + entry.created_by + ' über ' + (CHANNEL_LABELS[entry.channel] ?? entry.channel)));

    return facts;
}

function describeActions(entry) {
    const fragment = document.createDocumentFragment();
    if (!entry.can_edit) {
        return fragment;
    }
    if (entry.status === 'verworfen') {
        fragment.appendChild(actionButton('Beleg wiederherstellen', 'link-button', () => restoreDocument(entry.id)));

        return fragment;
    }
    fragment.appendChild(actionButton('Beleg verwerfen', 'link-button', discardCurrent));
    fragment.appendChild(element('small', 'Verworfene Belege bleiben gespeichert und lassen sich wiederherstellen.'));

    return fragment;
}

function renderDetail(entry) {
    clearChildren(detailBody);
    const title = documentTitle(entry);
    pageTitle.textContent = title;
    pageTitle.classList.add('detail-title');
    pageTitle.classList.remove('sr-only');
    window.document.title = title + ' – GARAGE';

    if (entry.status === 'verworfen') {
        const reason = entry.status_reason ?? 'ohne Angabe';
        detailBody.appendChild(element('p', 'Dieser Beleg ist verworfen: ' + reason, 'note'));
    }
    if (entry.status === 'wartet') {
        detailBody.appendChild(describeMissingOriginal(entry));
    } else {
        detailBody.appendChild(describePreview(entry));
        if (entry.original_url !== null) {
            detailBody.appendChild(describeOriginalButton(entry));
        }
    }
    detailBody.appendChild(describeFacts(entry));
    if (entry.text_content !== null && entry.text_content !== '') {
        const details = element('details');
        details.appendChild(element('summary', 'Erkannter Text'));
        details.appendChild(element('pre', entry.text_content, 'text'));
        detailBody.appendChild(details);
    }
    detailBody.appendChild(describeActions(entry));
}

async function showDetail(documentId) {
    listView.hidden = true;
    backLink.hidden = false;
    backLink.href = '#belege';
    detailView.hidden = false;
    hideError(detailError);
    clearChildren(detailBody);
    pageIndex = 0;
    try {
        current = await request('GET', 'documents/' + documentId);
    } catch (error) {
        pageTitle.textContent = 'Beleg';
        showError(detailError, error.message);
        return;
    }
    renderDetail(current);
}

function route() {
    hideToast();
    pageTitle.classList.remove('detail-title');
    pageTitle.classList.add('sr-only');
    const match = DETAIL_HASH.exec(location.hash);
    if (match === null) {
        showList();
        return;
    }
    showDetail(Number(match[1]));
}

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
    statusLine.textContent = '';
    viewListButton.addEventListener('click', () => setViewMode('liste'));
    viewGalleryButton.addEventListener('click', () => setViewMode('galerie'));
    window.addEventListener('hashchange', route);
    route();
}

mountShell({ active: '' });
start();
