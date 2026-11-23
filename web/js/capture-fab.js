// Kamera-Knopf am Fahrzeug: Foto aufnehmen, verkleinern, hochladen und ohne Seitenwechsel in den Eingang dieses Fahrzeugs legen.
import { request, upload } from './api.js';
import { buildUploadForm, prepareFile, uploadBytes } from './capture.js';
import { actionButton, clearChildren, element } from './dom.js';
import * as icons from './icons.js';
import { openSheet } from './sheet.js';

const NOTE_MAX = 200;

/** Knopf (ein `<label>` mit verstecktem Datei-Feld: am Handy öffnet sich die Kamera, am Rechner die Dateiauswahl) und Statusleiste. */
export function mountCameraButton({ buttonHost, statusHost, vehicleId, vehicleLabel }) {
    const label = element('label', undefined, 'fab');
    label.insertAdjacentHTML('beforeend', icons.cam);
    label.appendChild(element('span', 'Beleg'));
    const input = element('input', undefined, 'sr-only');
    input.type = 'file';
    input.accept = 'image/*';
    input.capture = 'environment';
    input.setAttribute('aria-label', 'Beleg für ' + vehicleLabel + ' fotografieren');
    label.appendChild(input);
    buttonHost.appendChild(label);

    let busy = false;

    function showBar(build) {
        clearChildren(statusHost);
        const bar = element('div', undefined, 'banner');
        bar.setAttribute('role', 'status');
        build(bar);
        statusHost.appendChild(bar);
    }

    function editNote(inboxId) {
        const wrapper = element('div', undefined, 'field');
        const noteLabel = element('label', 'Notiz (höchstens ' + NOTE_MAX + ' Zeichen)');
        noteLabel.htmlFor = 'inbox-note';
        const field = element('input');
        field.id = 'inbox-note';
        field.type = 'text';
        field.maxLength = NOTE_MAX;
        field.placeholder = 'z. B. Inspektion Mai';
        wrapper.appendChild(noteLabel);
        wrapper.appendChild(field);
        openSheet({
            title: 'Notiz zum Foto',
            body: wrapper,
            actions: [
                { label: 'Abbrechen', role: 'cancel' },
                {
                    label: 'Speichern',
                    role: 'primary',
                    onClick: async () => {
                        try {
                            await request('PATCH', 'inbox/' + inboxId, { note: field.value.trim() });
                        } catch (error) {
                            showBar((bar) => bar.appendChild(element('span', 'Die Notiz konnte nicht gespeichert werden: ' + error.message)));
                        }
                    },
                },
            ],
        });
    }

    function showDone(inboxId) {
        showBar((bar) => {
            bar.appendChild(element('span', 'Foto liegt im Eingang des ' + vehicleLabel + '.'));
            bar.appendChild(actionButton('Notiz', 'btn sm', () => editNote(inboxId)));
            bar.appendChild(actionButton('Schließen', 'btn quiet sm', () => clearChildren(statusHost)));
        });
    }

    function showFailure(message, retry) {
        showBar((bar) => {
            bar.appendChild(element('b', 'Hochladen fehlgeschlagen'));
            bar.appendChild(element('span', message));
            bar.appendChild(actionButton('Erneut versuchen', 'btn sm', retry));
        });
    }

    function showProgress(text, fraction) {
        showBar((bar) => {
            bar.appendChild(element('span', text));
            const track = element('div', undefined, 'progress');
            track.setAttribute('aria-hidden', 'true');
            const fill = element('span');
            fill.style.width = Math.round(fraction * 100) + '%';
            track.appendChild(fill);
            bar.appendChild(track);
        });
    }

    // Der Upload eines Fotos wird bei „Erneut versuchen“ nicht wiederholt, wenn das Dokument schon angelegt ist.
    async function send(state) {
        if (busy) {
            return;
        }
        busy = true;
        try {
            if (state.documentId === null) {
                const form = buildUploadForm(state.prepared);
                form.append('vehicle_id', String(vehicleId));
                const bytes = uploadBytes(state.prepared);
                const document = await upload('documents', form, (fraction) => showProgress('Lade hoch … ' + Math.round(fraction * 100) + ' % (' + Math.round(bytes / 1024) + ' KB)', fraction));
                state.documentId = document.id;
            }
            const created = await request('POST', 'inbox', { document_ids: [state.documentId], vehicle_id: vehicleId });
            showDone(created.id);
        } catch (error) {
            showFailure(error.message, () => send(state));
        } finally {
            busy = false;
        }
    }

    input.addEventListener('change', async () => {
        const file = input.files[0];
        input.value = '';
        if (file === undefined || busy) {
            return;
        }
        if (!file.type.startsWith('image/')) {
            showFailure('„' + file.name + '“ ist kein Foto. PDFs lädst du auf der Seite Eingang hoch.', () => input.click());

            return;
        }
        showProgress('Verkleinere Foto …', 0);
        let prepared;
        try {
            prepared = await prepareFile(file, (text) => showProgress(text, 0));
        } catch (error) {
            showFailure(error.message, () => input.click());

            return;
        }
        await send({ prepared, documentId: null });
    });
}
