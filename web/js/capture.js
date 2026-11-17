// Neuer Eintrag im Eingang: Dateien wählen, im Browser aufbereiten, hochladen, in den Eingang legen.
import { request, upload } from './api.js';
import { actionButton, clearChildren, element, formatBytes, hideError, showError } from './dom.js';
import { shrinkPhoto } from './image.js';
import { renderPages } from './pdfpages.js';

const MAX_FILE_BYTES = 30 * 1024 * 1024;
const MAX_PHOTO_EDGE = 2560;
const PDF_PAGE_EDGE = 1568;
const MAX_PDF_PAGES = 20;
const PDF_TYPE = 'application/pdf';
const UNKNOWN_VEHICLE_LABEL = 'Weiß ich nicht – Agent ordnet zu';

function pageWord(count) {
    if (count === 1) {
        return '1 Seite';
    }

    return count + ' Seiten';
}

async function preparePhoto(file, onProgress) {
    onProgress('Verkleinere Foto …');
    const { blob, wasResized } = await shrinkPhoto(file, MAX_PHOTO_EDGE);
    if (blob.size > MAX_FILE_BYTES) {
        throw new Error('„' + file.name + '“ ist auch verkleinert größer als 30 MB.');
    }
    let sizeText = formatBytes(blob.size);
    if (wasResized) {
        sizeText += ' nach Verkleinern';
    }

    return { name: file.name, file: blob, pages: [], pageCount: 1, previewBlob: blob, info: 'Foto · ' + sizeText };
}

async function preparePdf(file, onProgress) {
    if (file.size > MAX_FILE_BYTES) {
        throw new Error('„' + file.name + '“ ist größer als 30 MB.');
    }
    const pages = await renderPages(file, PDF_PAGE_EDGE, MAX_PDF_PAGES, (pageNumber, pageTotal) => {
        onProgress('Rendere PDF-Seite ' + pageNumber + ' von ' + pageTotal + ' …');
    });

    return { name: file.name, file, pages, pageCount: pages.length, previewBlob: pages[0], info: 'PDF · ' + pages.length + ' Seiten werden als Bilder gerendert' };
}

/** Bereitet eine gewählte Datei für den Upload vor; wirft einen Fehler mit verständlichem Text. `onProgress(text)` meldet den Schritt. */
export async function prepareFile(file, onProgress) {
    if (file.type === PDF_TYPE) {
        return preparePdf(file, onProgress);
    }
    if (file.type.startsWith('image/')) {
        return preparePhoto(file, onProgress);
    }

    throw new Error('„' + file.name + '“ ist weder Foto noch PDF. Gewählt werden können Fotos und PDFs.');
}

/** Multipart-Körper für `POST documents` und `POST documents/{id}/file`: Original als `file`, bei PDFs die Seitenbilder als `pages[]`. */
export function buildUploadForm(prepared) {
    const form = new FormData();
    form.append('file', prepared.file, prepared.name);
    prepared.pages.forEach((page, index) => form.append('pages[]', page, 'seite-' + (index + 1) + '.jpg'));

    return form;
}

export function uploadBytes(prepared) {
    return prepared.pages.reduce((sum, page) => sum + page.size, prepared.file.size);
}

/**
 * Verdrahtet den Bereich „Neuer Eintrag“ von eingang.html. `context` stammt aus `loadContext()`, `onCreated` lädt die Liste neu.
 * Gibt `addFiles(fileList)` zurück: der Einstieg für Dateien aus „Teilen“.
 */
export function setupCapture(context, undoBar, onCreated) {
    const startSection = document.getElementById('capture-start');
    const newSection = document.getElementById('capture-new');
    const cameraInput = document.getElementById('input-camera');
    const fileInput = document.getElementById('input-files');
    const cameraButton = document.getElementById('button-camera');
    const filesButton = document.getElementById('button-files');
    const moreButton = document.getElementById('button-more');
    const pageList = document.getElementById('page-list');
    const vehicleSelect = document.getElementById('capture-vehicle');
    const garageField = document.getElementById('capture-garage-field');
    const garageSelect = document.getElementById('capture-garage');
    const noteInput = document.getElementById('capture-note');
    const submitButton = document.getElementById('capture-submit');
    const cancelButton = document.getElementById('capture-cancel');
    const progressText = document.getElementById('capture-progress-text');
    const progressBar = document.getElementById('capture-progress');
    const progressFill = progressBar.firstElementChild;
    const errorNode = document.getElementById('capture-error');

    let entries = [];
    let isBusy = false;

    function showProgress(text, fraction) {
        progressText.textContent = text;
        progressBar.hidden = fraction === undefined;
        if (fraction !== undefined) {
            progressFill.style.width = Math.round(fraction * 100) + '%';
        }
    }

    function clearProgress() {
        progressText.textContent = '';
        progressBar.hidden = true;
    }

    function totalPages() {
        return entries.reduce((sum, entry) => sum + entry.pageCount, 0);
    }

    function setBusy(busy) {
        isBusy = busy;
        for (const control of [cameraButton, filesButton, moreButton, submitButton, cancelButton, vehicleSelect, garageSelect, noteInput]) {
            control.disabled = busy;
        }
        // inert statt Einzelknöpfe: ↑ an der ersten und ↓ an der letzten Seite bleiben so dauerhaft gesperrt.
        pageList.inert = busy;
    }

    function moveEntry(index, step) {
        const target = index + step;
        [entries[index], entries[target]] = [entries[target], entries[index]];
        renderPageList();
    }

    function removeEntry(index) {
        const [removed] = entries.splice(index, 1);
        renderPageList();
        undoBar.show('Seite entfernt.', () => {
            entries.splice(index, 0, removed);
            renderPageList();
        });
        const nextRemove = pageList.querySelectorAll('[data-remove]')[Math.min(index, entries.length - 1)];
        if (nextRemove !== undefined) {
            nextRemove.focus();
        } else {
            cameraButton.focus();
        }
    }

    function describeEntry(entry, index) {
        const row = element('div', undefined, 'page');
        const thumb = element('div', undefined, 'thumb');
        thumb.setAttribute('aria-hidden', 'true');
        const image = element('img');
        image.alt = '';
        image.src = entry.previewUrl;
        thumb.appendChild(image);
        row.appendChild(thumb);

        const text = element('div');
        text.appendChild(element('strong', entry.name));
        text.appendChild(element('small', entry.info));
        row.appendChild(text);

        const actions = element('div', undefined, 'page-actions');
        const up = actionButton('↑', '', () => moveEntry(index, -1));
        up.setAttribute('aria-label', 'Seite ' + (index + 1) + ' nach oben');
        up.disabled = index === 0;
        const down = actionButton('↓', '', () => moveEntry(index, 1));
        down.setAttribute('aria-label', 'Seite ' + (index + 1) + ' nach unten');
        down.disabled = index === entries.length - 1;
        const remove = actionButton('✕', '', () => removeEntry(index));
        remove.setAttribute('aria-label', 'Seite ' + (index + 1) + ' entfernen');
        remove.dataset.remove = String(index);
        actions.append(up, down, remove);
        row.appendChild(actions);

        return row;
    }

    function renderPageList() {
        clearChildren(pageList);
        entries.forEach((entry, index) => pageList.appendChild(describeEntry(entry, index)));
        const hasEntries = entries.length > 0;
        newSection.hidden = !hasEntries;
        startSection.hidden = hasEntries;
        submitButton.textContent = 'In den Eingang legen (' + pageWord(totalPages()) + ')';
    }

    function fillVehicleSelect() {
        clearChildren(vehicleSelect);
        const unknown = element('option', UNKNOWN_VEHICLE_LABEL);
        unknown.value = '';
        vehicleSelect.appendChild(unknown);
        const hasSeveralGarages = context.writableGarages.length > 1;
        for (const garage of context.writableGarages) {
            let parent = vehicleSelect;
            if (hasSeveralGarages) {
                parent = element('optgroup');
                parent.label = garage.name;
                vehicleSelect.appendChild(parent);
            }
            for (const vehicle of garage.vehicles) {
                const option = element('option', vehicle.label);
                option.value = String(vehicle.id);
                parent.appendChild(option);
            }
        }
        clearChildren(garageSelect);
        for (const garage of context.writableGarages) {
            const option = element('option', garage.name);
            option.value = String(garage.id);
            garageSelect.appendChild(option);
        }
    }

    function updateGarageField() {
        const needsGarageChoice = context.writableGarages.length > 1 && vehicleSelect.value === '';
        garageField.hidden = !needsGarageChoice;
    }

    function garageIdForUpload() {
        if (vehicleSelect.value !== '') {
            const vehicle = context.vehicles.find((candidate) => String(candidate.id) === vehicleSelect.value);

            return vehicle.garageId;
        }
        if (context.writableGarages.length > 1) {
            return Number(garageSelect.value);
        }

        return null;
    }

    async function addFiles(fileList) {
        if (isBusy) {
            return;
        }
        hideError(errorNode);
        const files = Array.from(fileList);
        setBusy(true);
        const problems = [];
        for (let index = 0; index < files.length; index++) {
            const prefix = files.length > 1 ? '(' + (index + 1) + ' von ' + files.length + ') ' : '';
            try {
                const prepared = await prepareFile(files[index], (step) => showProgress(prefix + step));
                prepared.previewUrl = URL.createObjectURL(prepared.previewBlob);
                prepared.documentId = null;
                entries.push(prepared);
            } catch (error) {
                problems.push(error.message);
            }
        }
        clearProgress();
        setBusy(false);
        renderPageList();
        if (problems.length > 0) {
            showError(errorNode, problems.join(' '));
        }
        if (entries.length > 0 && problems.length === 0) {
            moreButton.focus();
        }
    }

    // Jede Datei ein eigenes Dokument; bereits angelegte Dokumente (`documentId`) werden bei einem zweiten Versuch nicht noch einmal geschickt.
    async function uploadEntries(garageId, vehicleId) {
        const totalBytes = entries.reduce((sum, entry) => sum + uploadBytes(entry), 0);
        let doneBytes = 0;
        for (const entry of entries) {
            const entryBytes = uploadBytes(entry);
            if (entry.documentId === null) {
                const form = buildUploadForm(entry);
                if (garageId !== null) {
                    form.append('garage_id', String(garageId));
                }
                if (vehicleId !== '') {
                    form.append('vehicle_id', vehicleId);
                }
                const document = await upload('documents', form, (fraction) => {
                    showProgress('Lade hoch … ' + Math.round(((doneBytes + fraction * entryBytes) / totalBytes) * 100) + ' %', (doneBytes + fraction * entryBytes) / totalBytes);
                });
                entry.documentId = document.id;
            }
            doneBytes += entryBytes;
        }
    }

    function showUploadFailure(message) {
        showError(errorNode, 'Hochladen fehlgeschlagen: ' + message + ' ');
        errorNode.appendChild(actionButton('Erneut versuchen', 'link-button', submit));
    }

    async function submit() {
        if (isBusy || entries.length === 0) {
            return;
        }
        hideError(errorNode);
        setBusy(true);
        const vehicleId = vehicleSelect.value;
        try {
            showProgress('Lade hoch … 0 %', 0);
            await uploadEntries(garageIdForUpload(), vehicleId);
            const body = { document_ids: entries.map((entry) => entry.documentId), note: noteInput.value.trim() };
            if (vehicleId !== '') {
                body.vehicle_id = Number(vehicleId);
            }
            const created = await request('POST', 'inbox', body);
            finish(created.id);
        } catch (error) {
            clearProgress();
            setBusy(false);
            showUploadFailure(error.message);
        }
    }

    function finish(inboxId) {
        clearProgress();
        reset();
        onCreated();
        undoBar.show('Liegt im Eingang. Ein Agent erfasst ihn.', async () => {
            try {
                await request('POST', 'inbox/' + inboxId + '/discard');
            } catch (error) {
                showError(errorNode, 'Rückgängig machen ging nicht: ' + error.message);
            }
            onCreated();
        });
        cameraButton.focus();
    }

    function reset() {
        for (const entry of entries) {
            URL.revokeObjectURL(entry.previewUrl);
        }
        entries = [];
        noteInput.value = '';
        vehicleSelect.value = '';
        hideError(errorNode);
        updateGarageField();
        setBusy(false);
        renderPageList();
    }

    cameraButton.addEventListener('click', () => cameraInput.click());
    moreButton.addEventListener('click', () => cameraInput.click());
    filesButton.addEventListener('click', () => fileInput.click());
    for (const input of [cameraInput, fileInput]) {
        input.addEventListener('change', async () => {
            await addFiles(input.files);
            // Dieselbe Datei soll sich erneut wählen lassen, auch nach „Entfernen“.
            input.value = '';
        });
    }
    vehicleSelect.addEventListener('change', updateGarageField);
    submitButton.addEventListener('click', submit);
    cancelButton.addEventListener('click', () => {
        reset();
        cameraButton.focus();
    });

    fillVehicleSelect();
    updateGarageField();
    renderPageList();

    return { addFiles };
}
