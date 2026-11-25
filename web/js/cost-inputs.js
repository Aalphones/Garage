// Blätter „Tankung eintragen“ und „Ausgabe eintragen“ (ADR-025). Nur für Rollen mit Schreibrecht aufrufen.
import { ApiError, request } from './api.js';
import { element, hideError, showError } from './dom.js';
import { dropdown } from './dropdown.js';
import { openSheet } from './sheet.js';
import { toast } from './toast.js';
import { formatKm } from './widgets.js';

/** Werte der API mit ihrem Anzeigenamen, in der Reihenfolge der Auswahl. */
export const EXPENSE_CATEGORIES = [
    { value: 'parken', label: 'Parken' },
    { value: 'waesche', label: 'Wäsche' },
    { value: 'pflege', label: 'Pflege' },
    { value: 'maut', label: 'Maut' },
    { value: 'reifeneinlagerung', label: 'Reifeneinlagerung' },
    { value: 'bussgeld', label: 'Bußgeld' },
    { value: 'sonstiges', label: 'Sonstiges' },
];

const KM_MAX = 2_000_000;
const LITERS_MIN = 0.5;
const LITERS_MAX = 200;
const FUEL_EURO_MAX = 1000;
const NOTE_MAX = 200;
const INTEGER_PATTERN = /^\d+$/;
const DECIMAL_PATTERN = /^\d+(\.\d{1,2})?$/;
const RESULT_CONFLICT = 'klaerfall';
const RESULT_UNCHANGED = 'unveraendert';

/** Heutiger Kalendertag des Geräts als `YYYY-MM-DD`. */
function todayIso() {
    return new Date().toLocaleDateString('sv-SE');
}

function errorLine(id) {
    const error = element('p', undefined, 'error');
    error.id = id;
    error.setAttribute('role', 'alert');
    error.tabIndex = -1;
    error.hidden = true;

    return error;
}

/** Eingabefeld mit Beschriftung und eigener Fehlerzeile darunter. */
function inputField(id, labelText, attributes) {
    const field = element('div', undefined, 'field');
    const label = element('label', labelText);
    label.htmlFor = id;
    const input = element('input');
    input.id = id;
    input.autocomplete = 'off';
    Object.assign(input, attributes);
    const error = errorLine(id + '-error');
    field.append(label, input, error);

    return {
        field,
        input,
        fail(message) {
            input.setAttribute('aria-describedby', error.id);
            input.setAttribute('aria-invalid', 'true');
            showError(error, message);
        },
        clear() {
            input.removeAttribute('aria-describedby');
            input.removeAttribute('aria-invalid');
            hideError(error);
        },
    };
}

function dateField(id) {
    return inputField(id, 'Datum', { type: 'date', value: todayIso(), max: todayIso() });
}

function submitOnEnter(fields) {
    for (const { input } of fields) {
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                document.querySelector('.sheet .btn.primary')?.click();
            }
        });
    }
}

/** Zahl in deutscher Schreibweise (`1.234,50`) oder mit Punkt (`38.4`) als Punkt-Text; sonst `null`. */
function decimalText(rawText) {
    let text = rawText.trim();
    if (text.includes(',')) {
        text = text.replace(/\./g, '').replace(',', '.');
    }

    return DECIMAL_PATTERN.test(text) ? text : null;
}

function isValidDate(value) {
    return value !== '' && value <= todayIso();
}

/** Speichert und zeigt den Grund im ersten Fehlerfeld; doppeltes Tippen sendet nur einmal. Gibt die Antwort oder `null`. */
async function send(path, payload, state, errorField, fallback) {
    if (state.isSaving) {
        return null;
    }
    state.isSaving = true;
    try {
        return await request('POST', path, payload);
    } catch (error) {
        errorField.fail(error instanceof ApiError ? error.message : fallback);

        return null;
    } finally {
        state.isSaving = false;
    }
}

// --- Tankung ----------------------------------------------------------------------------------------------------

function fullToggle() {
    const label = element('label', undefined, 'toggle');
    const checkbox = element('input');
    checkbox.type = 'checkbox';
    checkbox.checked = true;
    const track = element('span', undefined, 'tr');
    track.setAttribute('aria-hidden', 'true');
    const text = element('span', 'Voll getankt', 'lbl');
    text.appendChild(element('small', 'Verbrauch rechnet GARAGE zwischen zwei Volltankungen.'));
    label.append(checkbox, track, text);

    return { label, checkbox };
}

function readFuel(form) {
    for (const field of [form.date, form.km, form.liters, form.amount]) {
        field.clear();
    }
    if (!isValidDate(form.date.input.value)) {
        form.date.fail('Das Datum darf nicht in der Zukunft liegen.');

        return null;
    }
    const kmText = form.km.input.value.trim().replace(/\./g, '');
    if (!INTEGER_PATTERN.test(kmText) || Number(kmText) > KM_MAX) {
        form.km.fail('Bitte eine ganze Zahl von 0 bis 2.000.000 eintragen.');

        return null;
    }
    const liters = decimalText(form.liters.input.value);
    if (liters === null || Number(liters) < LITERS_MIN || Number(liters) > LITERS_MAX) {
        form.liters.fail('Bitte 0,5 bis 200 Liter eintragen.');

        return null;
    }
    const amount = decimalText(form.amount.input.value);
    if (amount === null || Number(amount) <= 0 || Number(amount) > FUEL_EURO_MAX) {
        form.amount.fail('Bitte einen Betrag bis 1.000 € eintragen.');

        return null;
    }

    return { fueled_on: form.date.input.value, odometer_km: Number(kmText), liters, amount_eur: amount, is_full: form.full.checked };
}

function fuelMessage(outcome) {
    if (outcome.odometer.result === RESULT_CONFLICT) {
        return 'Tankung gespeichert · Kilometerstand ist ein Klärfall';
    }
    if (outcome.result === RESULT_CONFLICT) {
        return 'Tankung widerspricht einem Eintrag · siehe Klärfälle';
    }
    if (outcome.result === RESULT_UNCHANGED) {
        return 'Diese Tankung ist schon eingetragen';
    }
    if (outcome.consumption_l_100km !== null) {
        return 'Tankung gespeichert · ' + outcome.consumption_l_100km.toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' l/100 km';
    }

    return 'Tankung gespeichert';
}

async function saveFuel(vehicleId, form, state, onSaved) {
    const payload = readFuel(form);
    if (payload === null) {
        return false;
    }
    const outcome = await send('vehicles/' + vehicleId + '/fuel', payload, state, form.km, 'Die Tankung konnte nicht gespeichert werden.');
    if (outcome === null) {
        return false;
    }
    toast(fuelMessage(outcome));
    onSaved(outcome.result === RESULT_CONFLICT || outcome.odometer.result === RESULT_CONFLICT);

    return true;
}

/** `kmEstimate`: Schätzung aus der Akte oder `null`; `onSaved(hasNewOpenItem)` lädt neu. */
export function openFuelSheet(vehicle, kmEstimate, onSaved) {
    const estimate = kmEstimate?.km_estimate;
    const date = dateField('fuel-date');
    const km = inputField('fuel-km', 'Kilometer laut Tacho', { type: 'text', inputMode: 'numeric', placeholder: typeof estimate === 'number' ? '≈ ' + formatKm(estimate) : '' });
    const liters = inputField('fuel-liters', 'Liter', { type: 'text', inputMode: 'decimal', placeholder: 'z. B. 38,4' });
    const amount = inputField('fuel-amount', 'Betrag gesamt (€)', { type: 'text', inputMode: 'decimal', placeholder: 'z. B. 66,20' });
    const toggle = fullToggle();
    const grid = element('div', undefined, 'grid2');
    grid.append(date.field, km.field, liters.field, amount.field);
    const body = element('div');
    body.append(grid, toggle.label, element('p', 'Gilt als deine Angabe. Der km-Stand zählt auch als Kilometerstand; ist er kleiner als ein früherer, entsteht ein Klärfall.', 'muted small'));
    submitOnEnter([date, km, liters, amount]);

    const form = { date, km, liters, amount, full: toggle.checkbox };
    const state = { isSaving: false };
    openSheet({
        title: 'Tankung eintragen · ' + vehicle.label,
        body,
        actions: [
            { label: 'Abbrechen', role: 'cancel' },
            { label: 'Tankung speichern', role: 'primary', onClick: () => saveFuel(vehicle.id, form, state, onSaved) },
        ],
    });
}

// --- Ausgabe ----------------------------------------------------------------------------------------------------

function readExpense(form) {
    for (const field of [form.amount, form.date, form.note]) {
        field.clear();
    }
    const amount = decimalText(form.amount.input.value);
    if (amount === null || Number(amount) <= 0) {
        form.amount.fail('Bitte einen Betrag über 0 € eintragen.');

        return null;
    }
    if (!isValidDate(form.date.input.value)) {
        form.date.fail('Das Datum darf nicht in der Zukunft liegen.');

        return null;
    }
    const payload = { spent_on: form.date.input.value, category: form.category(), amount_eur: amount };
    const note = form.note.input.value.trim();
    if (note !== '') {
        payload.note = note;
    }

    return payload;
}

async function saveExpense(vehicleId, form, state, onSaved) {
    const payload = readExpense(form);
    if (payload === null) {
        return false;
    }
    const outcome = await send('vehicles/' + vehicleId + '/expenses', payload, state, form.amount, 'Die Ausgabe konnte nicht gespeichert werden.');
    if (outcome === null) {
        return false;
    }
    let message = 'Ausgabe gespeichert';
    if (outcome.result === RESULT_CONFLICT) {
        message = 'Ausgabe widerspricht einem Eintrag · siehe Klärfälle';
    } else if (outcome.result === RESULT_UNCHANGED) {
        message = 'Diese Ausgabe ist schon eingetragen';
    }
    toast(message);
    onSaved(outcome.result === RESULT_CONFLICT);

    return true;
}

/** `onSaved(hasNewOpenItem)` lädt neu. */
export function openExpenseSheet(vehicleId, onSaved) {
    let category = EXPENSE_CATEGORIES[0].value;
    const categoryField = element('div', undefined, 'field');
    categoryField.appendChild(element('span', 'Kategorie', 'field-title'));
    const picker = dropdown(categoryField, {
        label: 'Kategorie',
        options: EXPENSE_CATEGORIES,
        value: category,
        onChange: (value) => {
            category = value;
        },
    });
    const amount = inputField('expense-amount', 'Betrag (€)', { type: 'text', inputMode: 'decimal', placeholder: 'z. B. 6,50' });
    const date = dateField('expense-date');
    const note = inputField('expense-note', 'Notiz (optional)', { type: 'text', maxLength: NOTE_MAX, placeholder: 'z. B. Parkhaus Flughafen' });
    const grid = element('div', undefined, 'grid2');
    grid.append(categoryField, amount.field, date.field, note.field);
    const body = element('div');
    body.append(grid, element('p', 'Zählt im Block Betrieb am Tag der Ausgabe. Werkstatt und Verträge erfasst der Agent aus Belegen.', 'muted small'));
    submitOnEnter([amount, date, note]);

    const form = { category: () => category, amount, date, note };
    const state = { isSaving: false };
    openSheet({
        title: 'Ausgabe eintragen',
        body,
        actions: [
            {
                label: 'Abbrechen',
                role: 'cancel',
                onClick: () => picker.destroy(),
            },
            { label: 'Ausgabe speichern', role: 'primary', onClick: () => saveExpense(vehicleId, form, state, onSaved) },
        ],
    });
}
