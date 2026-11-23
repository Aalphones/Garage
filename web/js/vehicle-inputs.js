// Kleine Eingaben im Browser (ADR-021): Kilometerstand, Stammdaten-Wert korrigieren, Notiz, Titelbild. Nur für Rollen mit Schreibrecht aufrufen.
import { ApiError, request } from './api.js';
import { element, hideError, showError } from './dom.js';
import { openSheet } from './sheet.js';
import { toast } from './toast.js';

const KM_MAX = 2_000_000;
const KM_NOTE_MAX = 200;
const TOPIC_MAX = 120;
const BODY_MAX = 20_000;
const INTEGER_PATTERN = /^\d+$/;

function failureText(error, fallback) {
    return error instanceof ApiError ? error.message : fallback;
}

/** Heutiger Kalendertag des Geräts als `YYYY-MM-DD`. */
function todayIso() {
    return new Date().toLocaleDateString('sv-SE');
}

/**
 * Ein Eingabefeld mit Beschriftung und eigener Fehlerzeile darunter. Der Fehler hängt per `aria-describedby` am Feld,
 * sobald er sichtbar ist.
 */
function fieldBlock(idPrefix, labelText, control) {
    const field = element('div', undefined, 'field');
    const label = element('label', labelText);
    label.htmlFor = idPrefix;
    control.id = idPrefix;
    const error = element('p', undefined, 'error');
    error.id = idPrefix + '-error';
    error.setAttribute('role', 'alert');
    error.tabIndex = -1;
    error.hidden = true;
    field.append(label, control, error);

    return {
        field,
        control,
        fail(message) {
            control.setAttribute('aria-describedby', error.id);
            control.setAttribute('aria-invalid', 'true');
            showError(error, message);
        },
        clear() {
            control.removeAttribute('aria-describedby');
            control.removeAttribute('aria-invalid');
            hideError(error);
        },
    };
}

function textInput(value) {
    const input = element('input');
    input.type = 'text';
    input.autocomplete = 'off';
    input.value = value;

    return input;
}

/** Enter in einem einzeiligen Feld löst den Speichern-Knopf des Blatts aus. */
function submitOnEnter(input) {
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            document.querySelector('.sheet .btn.primary')?.click();
        }
    });
}

function hint(text) {
    return element('p', text, 'muted small');
}

// --- Kilometerstand ---------------------------------------------------------------------------------------------------

function validKm(rawText) {
    const text = rawText.trim().replace(/\./g, '');
    if (!INTEGER_PATTERN.test(text) || Number(text) > KM_MAX) {
        return null;
    }

    return Number(text);
}

/** `onSaved()` lädt die Übersicht neu. */
export function openOdometerSheet(vehicleId, onSaved) {
    const body = element('div');
    const kmInput = textInput('');
    kmInput.inputMode = 'numeric';
    kmInput.pattern = '[0-9]*';
    kmInput.required = true;
    kmInput.placeholder = 'z. B. 27350';
    const kmField = fieldBlock('km-value', 'Kilometer laut Tacho', kmInput);
    const dateInput = element('input');
    dateInput.type = 'date';
    dateInput.value = todayIso();
    dateInput.max = todayIso();
    const dateField = fieldBlock('km-date', 'Abgelesen am', dateInput);
    const noteInput = textInput('');
    noteInput.maxLength = KM_NOTE_MAX;
    noteInput.placeholder = 'z. B. vor dem Urlaub';
    const noteField = fieldBlock('km-note', 'Notiz (optional)', noteInput);
    body.append(kmField.field, dateField.field, noteField.field, hint('Gilt als deine Angabe. Ist der Stand kleiner als ein früherer, entsteht ein Klärfall statt eines Fehlers.'));
    for (const input of [kmInput, dateInput, noteInput]) {
        submitOnEnter(input);
    }

    openSheet({
        title: 'Kilometerstand eintragen',
        body,
        actions: [
            { label: 'Abbrechen', role: 'cancel' },
            {
                label: 'Stand speichern',
                role: 'primary',
                onClick: () => saveOdometer(vehicleId, { kmInput, kmField, dateInput, dateField, noteInput, noteField }, onSaved),
            },
        ],
    });
}

async function saveOdometer(vehicleId, form, onSaved) {
    form.kmField.clear();
    form.dateField.clear();
    form.noteField.clear();
    const km = validKm(form.kmInput.value);
    if (km === null) {
        form.kmField.fail('Bitte eine ganze Zahl von 0 bis 2.000.000 eintragen.');

        return false;
    }
    if (form.dateInput.value === '' || form.dateInput.value > todayIso()) {
        form.dateField.fail('Das Datum darf nicht in der Zukunft liegen.');

        return false;
    }
    const payload = { read_on: form.dateInput.value, km };
    if (form.noteInput.value.trim() !== '') {
        payload.note = form.noteInput.value.trim();
    }
    let outcome;
    try {
        outcome = await request('POST', 'vehicles/' + vehicleId + '/odometer', payload);
    } catch (error) {
        form.kmField.fail(failureText(error, 'Der Kilometerstand konnte nicht gespeichert werden.'));

        return false;
    }
    toast(outcome.result === 'klaerfall' ? 'Stand gespeichert — widerspricht einem früheren, siehe Klärfälle' : 'Kilometerstand gespeichert');
    onSaved();

    return true;
}

// --- Stammdaten-Wert korrigieren --------------------------------------------------------------------------------------

/**
 * `field`: Feldname aus der Akte; `label`: Anzeigename; `current`: aktueller Wert (Zahl oder Text); `inputKind`: `'text'`, `'date'` oder `'int'`.
 * Strittige Felder bekommen keinen Stift, die API lehnt sie mit 409 ab.
 */
export function openFactSheet(vehicleId, { field, label, current, inputKind }, onSaved) {
    const body = element('div');
    const input = textInput(String(current));
    if (inputKind === 'date') {
        input.type = 'date';
    }
    if (inputKind === 'int') {
        input.inputMode = 'numeric';
        input.pattern = '[0-9]*';
    }
    input.required = true;
    const valueField = fieldBlock('fact-value', label, input);
    body.append(valueField.field, hint('Dein Wert ersetzt den bisherigen sofort (Herkunft: Nutzer, bestätigt). Der alte Wert bleibt im Verlauf.'));
    submitOnEnter(input);

    openSheet({
        title: label + ' korrigieren',
        body,
        actions: [
            { label: 'Abbrechen', role: 'cancel' },
            {
                label: 'Wert speichern',
                role: 'primary',
                onClick: () => saveFact(vehicleId, { field, label, inputKind, input, valueField }, onSaved),
            },
        ],
    });
}

async function saveFact(vehicleId, form, onSaved) {
    form.valueField.clear();
    const text = form.input.value.trim();
    if (text === '') {
        form.valueField.fail('Bitte einen Wert eintragen.');

        return false;
    }
    if (form.inputKind === 'int' && !INTEGER_PATTERN.test(text)) {
        form.valueField.fail('Bitte eine ganze Zahl eintragen.');

        return false;
    }
    const value = form.inputKind === 'int' ? Number(text) : text;
    try {
        await request('PATCH', 'vehicles/' + vehicleId + '/facts', { field: form.field, value });
    } catch (error) {
        form.valueField.fail(failureText(error, 'Der Wert konnte nicht gespeichert werden.'));

        return false;
    }
    toast(form.label + ' gespeichert');
    onSaved();

    return true;
}

// --- Notiz --------------------------------------------------------------------------------------------------------------

/** `note`: `{ topic, body, revision }` zum Bearbeiten, `null` für eine neue Notiz. */
export function openNoteSheet(vehicleId, note, onSaved) {
    const isEdit = note !== null;
    const body = element('div');
    const topicInput = textInput(isEdit ? note.topic : '');
    topicInput.maxLength = TOPIC_MAX;
    topicInput.required = true;
    topicInput.placeholder = 'z. B. Reifendruck';
    const topicField = fieldBlock('note-topic', 'Thema', topicInput);
    const bodyInput = element('textarea');
    bodyInput.rows = 6;
    bodyInput.maxLength = BODY_MAX;
    bodyInput.required = true;
    bodyInput.value = isEdit ? note.body : '';
    const bodyField = fieldBlock('note-body', 'Text', bodyInput);
    const explanation = isEdit
        ? 'Speichern legt Revision ' + (note.revision + 1) + ' an; frühere bleiben erhalten.'
        : 'Gleiches Thema wie eine vorhandene Notiz ergänzt diese als neue Revision.';
    body.append(topicField.field, bodyField.field, hint(explanation));
    submitOnEnter(topicInput);

    openSheet({
        title: isEdit ? 'Notiz bearbeiten' : 'Notiz anlegen',
        body,
        actions: [
            { label: 'Abbrechen', role: 'cancel' },
            {
                label: 'Notiz speichern',
                role: 'primary',
                onClick: () => saveNote(vehicleId, { topicInput, topicField, bodyInput, bodyField }, onSaved),
            },
        ],
    });
}

async function saveNote(vehicleId, form, onSaved) {
    form.topicField.clear();
    form.bodyField.clear();
    const topic = form.topicInput.value.trim();
    const noteBody = form.bodyInput.value.trim();
    if (topic === '') {
        form.topicField.fail('Bitte ein Thema eintragen.');

        return false;
    }
    if (noteBody === '') {
        form.bodyField.fail('Bitte einen Text eintragen.');

        return false;
    }
    try {
        await request('PUT', 'vehicles/' + vehicleId + '/notes', { topic, body: noteBody });
    } catch (error) {
        form.bodyField.fail(failureText(error, 'Die Notiz konnte nicht gespeichert werden.'));

        return false;
    }
    toast('Notiz gespeichert');
    onSaved();

    return true;
}

// --- Titelbild ----------------------------------------------------------------------------------------------------------

/** Setzt das Foto als Titelbild des Fahrzeugs; gibt `true` bei Erfolg zurück, sonst zeigt die Leiste den Grund. */
export async function setCoverPhoto(vehicleId, documentId) {
    try {
        await request('POST', 'vehicles/' + vehicleId + '/cover', { document_id: documentId });
    } catch (error) {
        toast(failureText(error, 'Das Titelbild konnte nicht gesetzt werden.'));

        return false;
    }
    toast('Titelbild gesetzt');

    return true;
}
