// Aktionen im Fahrzeugkopf: Drucken, Markdown, Status ändern, Löschen (ADR-018, ADR-019). Status und Löschen nur für Inhaber.
import { ApiError, download, request } from './api.js';
import { actionButton, element, hideError, showError } from './dom.js';
import * as icons from './icons.js';
import { openSheet } from './sheet.js';
import { toast } from './toast.js';

const STATUS_CHOICES = [
    { key: 'aktiv', label: 'Aktiv' },
    { key: 'verkauft', label: 'Verkauft' },
    { key: 'stillgelegt', label: 'Stillgelegt' },
];
const REASON_MAX = 200;

function failureText(error, fallback) {
    return error instanceof ApiError ? error.message : fallback;
}

function actionBox(iconSvg, label, onClick, extraClass) {
    const button = actionButton('', extraClass === undefined ? 'btn quiet' : 'btn quiet ' + extraClass, onClick);
    button.insertAdjacentHTML('beforeend', iconSvg);
    button.appendChild(document.createTextNode(label));

    return button;
}

function errorBox() {
    const box = element('p', undefined, 'error');
    box.setAttribute('role', 'alert');
    box.tabIndex = -1;
    box.hidden = true;

    return box;
}

function reasonField(inputId, labelText) {
    const field = element('div', undefined, 'field');
    const label = element('label', labelText);
    label.htmlFor = inputId;
    const input = element('input');
    input.id = inputId;
    input.maxLength = REASON_MAX;
    input.autocomplete = 'off';
    field.appendChild(label);
    field.appendChild(input);

    return { field, input };
}

async function printAkte(vehicleId) {
    try {
        const { url } = await request('POST', 'vehicles/' + vehicleId + '/print-link', {});
        window.open(url, '_blank', 'noopener');
    } catch (error) {
        toast(failureText(error, 'Die Druckansicht konnte nicht geöffnet werden.'));
    }
}

async function downloadMarkdown(vehicleId) {
    try {
        const { blob, filename } = await download('vehicles/' + vehicleId + '/dossier');
        const url = URL.createObjectURL(blob);
        const anchor = element('a');
        anchor.href = url;
        anchor.download = filename;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(() => URL.revokeObjectURL(url), 10_000);
    } catch (error) {
        toast(failureText(error, 'Die Akte konnte nicht geladen werden.'));
    }
}

function openStatusSheet(vehicle, onChanged) {
    const body = element('div');
    body.appendChild(element('p', 'Verkaufte und stillgelegte Fahrzeuge bleiben mit ganzer Akte erhalten, zählen aber nicht mehr bei Fälligkeiten.', 'muted small'));
    const group = element('div', undefined, 'radio');
    group.setAttribute('role', 'radiogroup');
    group.setAttribute('aria-label', 'Status');
    for (const choice of STATUS_CHOICES) {
        const label = element('label');
        const input = element('input');
        input.type = 'radio';
        input.name = 'vehicle-status';
        input.value = choice.key;
        input.checked = vehicle.status === choice.key;
        label.appendChild(input);
        label.appendChild(document.createTextNode(' ' + choice.label));
        group.appendChild(label);
    }
    body.appendChild(group);
    const { field, input: reason } = reasonField('status-reason', 'Grund (optional)');
    reason.placeholder = 'z. B. verkauft an Privat, 11/2026';
    body.appendChild(field);
    const error = errorBox();
    body.appendChild(error);

    openSheet({
        title: 'Status des ' + vehicle.label + ' ändern',
        body,
        actions: [
            { label: 'Abbrechen', role: 'cancel' },
            {
                label: 'Status speichern',
                role: 'primary',
                onClick: async () => {
                    hideError(error);
                    const chosen = group.querySelector('input:checked');
                    const payload = { status: chosen === null ? vehicle.status : chosen.value };
                    if (reason.value.trim() !== '') {
                        payload.reason = reason.value.trim();
                    }
                    try {
                        await request('PATCH', 'vehicles/' + vehicle.id, payload);
                    } catch (failure) {
                        showError(error, failureText(failure, 'Der Status konnte nicht gespeichert werden.'));

                        return false;
                    }
                    toast('Status gespeichert');
                    onChanged();

                    return true;
                },
            },
        ],
    });
}

function openDeleteSheet(vehicle) {
    const body = element('div');
    body.appendChild(element('p', 'Das Fahrzeug verschwindet aus Garage, Fälligkeiten und Suche. Akte und Belege bleiben gespeichert und lassen sich 30 Tage wiederherstellen.', 'small'));
    const { field, input: reason } = reasonField('delete-reason', 'Grund');
    reason.required = true;
    reason.placeholder = 'z. B. doppelt angelegt';
    body.appendChild(field);
    const error = errorBox();
    body.appendChild(error);

    openSheet({
        title: vehicle.label + ' löschen?',
        body,
        actions: [
            { label: 'Abbrechen', role: 'cancel' },
            {
                label: 'Fahrzeug löschen',
                role: 'danger',
                onClick: async () => {
                    hideError(error);
                    try {
                        await request('POST', 'vehicles/' + vehicle.id + '/delete', { reason: reason.value.trim() });
                    } catch (failure) {
                        showError(error, failureText(failure, 'Das Fahrzeug konnte nicht gelöscht werden.'));

                        return false;
                    }
                    // Die Rückgängig-Leiste überlebt den Seitenwechsel nicht; die Garage zeigt sie nach dem Laden.
                    sessionStorage.setItem('garage.deleted', JSON.stringify({ id: vehicle.id, label: vehicle.label }));
                    location.href = '/garage/garage.html';

                    return true;
                },
            },
        ],
    });
    const deleteButton = document.querySelector('.sheet .btn.danger');
    deleteButton.disabled = true;
    reason.addEventListener('input', () => {
        deleteButton.disabled = reason.value.trim() === '';
    });
}

/** Füllt `host` (`.vacts`) mit den Aktionen; `data` ist die Antwort von `GET vehicles/{id}` samt `permissions`. `onChanged()` lädt das Fahrzeug neu. */
export function mountVehicleActions(host, data, onChanged) {
    const { vehicle, permissions } = data;
    host.appendChild(actionBox(icons.print, 'Drucken', () => printAkte(vehicle.id)));
    host.appendChild(actionBox(icons.doc, 'Markdown', () => downloadMarkdown(vehicle.id)));
    if (permissions.owner) {
        host.appendChild(actionBox(icons.car, 'Status', () => openStatusSheet(vehicle, onChanged)));
        host.appendChild(actionBox(icons.trash, 'Löschen', () => openDeleteSheet(vehicle), 'danger-text'));
    }
}
