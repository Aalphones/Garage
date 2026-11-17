// Einstieg für belege.html: Liste, Galerie und Detail (`#beleg/<id>`) der Belege.
import { request, upload } from './api.js';
import { buildUploadForm, prepareFile } from './capture.js';
import { actionButton, clearChildren, element, formatDay, hideError, link, showError } from './dom.js';
import { loadContext, requireSession } from './session.js';
import { registerShareWorker } from './share.js';
import { createUndoBar } from './undo.js';

registerShareWorker();

const VIEW_STORAGE_KEY = 'garage.belege.ansicht';
const DETAIL_HASH = /^#beleg\/(\d+)$/;
const DISCARD_REASON = 'In der Oberfläche verworfen';

const TYPE_LABELS = {
    foto: 'Foto',
    rechnung: 'Rechnung',
    hu_bericht: 'HU-Bericht',
    police: 'Police',
    bescheid: 'Bescheid',
    fahrzeugschein: 'Fahrzeugschein',
    kaufvertrag: 'Kaufvertrag',
    sonstiges: 'Sonstiges',
};

const TAG_LABELS = { aussen: 'Außen', innen: 'Innen', motorraum: 'Motorraum', tacho: 'Tacho', reifen: 'Reifen', schaden: 'Schaden' };

const TARGET_LABELS = {
    vehicles: 'Fahrzeug',
    events: 'Ereignis',
    damages: 'Schaden',
    recalls: 'Rückruf',
    tire_sets: 'Reifensatz',
    parts: 'Teil',
    measurements: 'Messwert',
    odometer_readings: 'Kilometerstand',
    registrations: 'Zulassung',
};

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
const appNav = document.getElementById('app-nav');
const listView = document.getElementById('list-view');
const detailView = document.getElementById('detail-view');
const listBody = document.getElementById('list-body');
const listError = document.getElementById('list-error');
const detailBody = document.getElementById('detail-body');
const detailError = document.getElementById('detail-error');
const chipsGroup = document.getElementById('vehicle-chips');
const viewListButton = document.getElementById('view-list');
const viewGalleryButton = document.getElementById('view-gallery');
const undoBar = createUndoBar(document.getElementById('undo-slot'));

let context = null;
let documents = [];
let vehicleFilter = null;
let viewMode = readViewMode();
let current = null;
let pageIndex = 0;

function readViewMode() {
    try {
        if (localStorage.getItem(VIEW_STORAGE_KEY) === 'galerie') {
            return 'galerie';
        }
    } catch {
        // Ohne Speicher bleibt es bei der Liste; die Wahl ist nur eine Anzeige-Einstellung.
    }

    return 'liste';
}

/** Ohne Titel (Eingang liefert keinen) nennen Typ und Eingangsdatum den Beleg, sonst hießen alle gleich. */
function documentTitle(entry) {
    if (entry.title !== '') {
        return entry.title;
    }

    return (TYPE_LABELS[entry.doc_type] ?? 'Beleg') + ' vom ' + formatDay(entry.created_at);
}

function vehicleName(vehicleId) {
    const vehicle = context.vehicles.find((candidate) => candidate.id === vehicleId);
    if (vehicle === undefined) {
        return 'Kein Fahrzeug';
    }

    return vehicle.label;
}

function documentDate(entry) {
    if (entry.captured_on !== null) {
        return formatDay(entry.captured_on);
    }

    return formatDay(entry.created_at);
}

function attachedWords(targets) {
    const words = [];
    for (const target of targets) {
        const word = TARGET_LABELS[target.target_table] ?? 'Datensatz';
        if (!words.includes(word)) {
            words.push(word);
        }
    }

    return words.join(', ');
}

function describeThumb(entry) {
    const thumb = element('div', undefined, 'thumb');
    thumb.setAttribute('aria-hidden', 'true');
    if (entry.preview_url !== null && entry.preview_url !== undefined) {
        const image = element('img');
        image.alt = '';
        image.loading = 'lazy';
        image.src = entry.preview_url;
        thumb.appendChild(image);
    } else if (entry.status === 'wartet') {
        thumb.textContent = '–';
    } else {
        thumb.textContent = 'PDF';
    }

    return thumb;
}

function describeState(entry) {
    if (entry.status === 'wartet') {
        return element('span', 'Original fehlt', 'state warn');
    }
    if (entry.status === 'zugeordnet') {
        return element('span', 'Hängt an ' + attachedWords(entry.attached_to), 'state done');
    }

    return element('span', 'Nicht zugeordnet', 'state');
}

function describeRow(entry) {
    const listItem = element('li');
    const row = link('', '#beleg/' + entry.id, 'row');
    row.appendChild(describeThumb(entry));

    const text = element('div');
    text.appendChild(element('strong', documentTitle(entry), 'title'));
    const meta = [TYPE_LABELS[entry.doc_type] ?? entry.doc_type];
    if (entry.photo_tag !== null) {
        meta.push(TAG_LABELS[entry.photo_tag] ?? entry.photo_tag);
    }
    meta.push(documentDate(entry), vehicleName(entry.vehicle_id));
    text.appendChild(element('small', meta.join(' · ')));
    text.appendChild(describeState(entry));
    row.appendChild(text);
    listItem.appendChild(row);

    return listItem;
}

function describeGroup(heading, group) {
    const fragment = document.createDocumentFragment();
    fragment.appendChild(element('h3', heading));
    const list = element('ul', undefined, 'plain-list');
    group.forEach((entry) => list.appendChild(describeRow(entry)));
    fragment.appendChild(list);

    return fragment;
}

function renderGallery(visible) {
    const gallery = element('div', undefined, 'gallery');
    for (const entry of visible) {
        const tile = link('', '#beleg/' + entry.id);
        tile.appendChild(describeThumb(entry));
        tile.appendChild(element('small', documentTitle(entry)));
        gallery.appendChild(tile);
    }

    return gallery;
}

function renderList() {
    clearChildren(listBody);
    viewListButton.setAttribute('aria-pressed', String(viewMode === 'liste'));
    viewGalleryButton.setAttribute('aria-pressed', String(viewMode === 'galerie'));

    const visible = documents.filter((entry) => vehicleFilter === null || entry.vehicle_id === vehicleFilter);
    if (visible.length === 0) {
        const empty = element('p', 'Noch keine Belege. Lege im ');
        empty.appendChild(link('Eingang', '/garage/eingang.html'));
        empty.appendChild(document.createTextNode(' ein Foto oder ein PDF ab.'));
        listBody.appendChild(empty);
        return;
    }
    if (viewMode === 'galerie') {
        listBody.appendChild(renderGallery(visible));
        return;
    }

    const open = visible.filter((entry) => entry.status !== 'zugeordnet');
    const assigned = visible.filter((entry) => entry.status === 'zugeordnet');
    if (open.length > 0) {
        listBody.appendChild(describeGroup('Offen', open));
    }
    if (assigned.length > 0) {
        listBody.appendChild(describeGroup('Zugeordnet', assigned));
    } else {
        const none = element('p', 'Noch keine zugeordneten Belege. Ein Agent ordnet sie zu, sobald er sie erfasst hat.');
        listBody.appendChild(none);
    }
}

function renderChips() {
    clearChildren(chipsGroup);
    const choices = [{ id: null, label: 'Alle' }, ...context.vehicles.map((vehicle) => ({ id: vehicle.id, label: vehicle.label }))];
    for (const choice of choices) {
        const chip = actionButton(choice.label, '', () => {
            vehicleFilter = choice.id;
            chipsGroup.querySelectorAll('button').forEach((other) => other.setAttribute('aria-pressed', String(other === chip)));
            renderList();
        });
        chip.setAttribute('aria-pressed', String(choice.id === vehicleFilter));
        chipsGroup.appendChild(chip);
    }
}

function setViewMode(mode) {
    viewMode = mode;
    try {
        localStorage.setItem(VIEW_STORAGE_KEY, mode);
    } catch {
        // Nicht speicherbar: die Ansicht gilt dann nur für diesen Besuch.
    }
    renderList();
}

async function showList() {
    detailView.hidden = true;
    backLink.hidden = true;
    appNav.hidden = false;
    pageTitle.textContent = 'GARAGE';
    window.document.title = 'Belege – GARAGE';
    listView.hidden = false;
    hideError(listError);
    try {
        documents = (await request('GET', 'documents')).documents;
    } catch (error) {
        showError(listError, error.message);
        return;
    }
    renderChips();
    renderList();
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
        fragment.appendChild(element('p', 'Zu diesem PDF gibt es keine Seitenbilder. Öffne das Original, um es zu lesen.', 'tip'));
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
    undoBar.show('Original abgelegt.');
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
    undoBar.show('Gespeichert.', async () => {
        try {
            current = await request('PATCH', 'documents/' + savedId, { [field]: previous });
            renderDetail(current);
        } catch (error) {
            showError(detailError, error.message);
        }
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
    undoBar.show('Beleg verworfen.', () => restoreDocument(discardedId));
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
    const facts = element('dl', undefined, 'facts');
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
    window.document.title = title + ' – GARAGE';

    if (entry.status === 'verworfen') {
        const reason = entry.status_reason ?? 'ohne Angabe';
        detailBody.appendChild(element('p', 'Dieser Beleg ist verworfen: ' + reason, 'tip'));
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
    appNav.hidden = true;
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
    undoBar.hide();
    pageTitle.classList.remove('detail-title');
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

start();
