// Belege als Liste oder Galerie: gemeinsam für belege.html und den Reiter „Belege“ eines Fahrzeugs.
import { request } from './api.js';
import { clearChildren, element, formatDay, link } from './dom.js';
import * as icons from './icons.js';
import { readView, viewSwitch } from './view-switch.js';
import { errorBlock } from './widgets.js';

const STORAGE_KEY = 'garage.view.belege';
const VIEWS = ['list', 'gal'];

export const TYPE_LABELS = {
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

export const TARGET_LABELS = {
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

/** Ohne Titel (Eingang liefert keinen) nennen Typ und Eingangsdatum den Beleg, sonst hießen alle gleich. */
export function documentTitle(entry) {
    if (entry.title !== '') {
        return entry.title;
    }

    return (TYPE_LABELS[entry.doc_type] ?? 'Beleg') + ' vom ' + formatDay(entry.created_at);
}

export function documentDate(entry) {
    if (entry.captured_on !== null) {
        return formatDay(entry.captured_on);
    }

    return formatDay(entry.created_at);
}

export function attachedWords(targets) {
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

/** `options`: `{ detailBase, vehicleNames, showVehicle }` — siehe `renderDocuments`. */
function describeRow(entry, options) {
    const listItem = element('li');
    const row = link('', options.detailBase + '#beleg/' + entry.id, 'entry');
    row.appendChild(describeThumb(entry));

    const text = element('div');
    text.appendChild(element('strong', documentTitle(entry), 'title'));
    const meta = [TYPE_LABELS[entry.doc_type] ?? entry.doc_type];
    if (entry.photo_tag !== null) {
        meta.push(TAG_LABELS[entry.photo_tag] ?? entry.photo_tag);
    }
    meta.push(documentDate(entry));
    if (options.showVehicle) {
        meta.push(options.vehicleNames.get(entry.vehicle_id) ?? 'Kein Fahrzeug');
    }
    text.appendChild(element('small', meta.join(' · ')));
    text.appendChild(describeState(entry));
    row.appendChild(text);
    listItem.appendChild(row);

    return listItem;
}

function describeGroup(heading, group, options) {
    const fragment = document.createDocumentFragment();
    fragment.appendChild(element('h3', heading));
    const list = element('ul', undefined, 'plain-list');
    group.forEach((entry) => list.appendChild(describeRow(entry, options)));
    fragment.appendChild(list);

    return fragment;
}

function renderGallery(visible, options) {
    const gallery = element('div', undefined, 'gallery');
    for (const entry of visible) {
        const tile = link('', options.detailBase + '#beleg/' + entry.id);
        tile.appendChild(describeThumb(entry));
        tile.appendChild(element('small', documentTitle(entry)));
        gallery.appendChild(tile);
    }

    return gallery;
}

function renderList(visible, options) {
    const fragment = document.createDocumentFragment();
    const open = visible.filter((entry) => entry.status !== 'zugeordnet');
    const assigned = visible.filter((entry) => entry.status === 'zugeordnet');
    if (open.length > 0) {
        fragment.appendChild(describeGroup('Offen', open, options));
    }
    if (assigned.length > 0) {
        fragment.appendChild(describeGroup('Zugeordnet', assigned, options));
    } else {
        fragment.appendChild(element('p', 'Noch keine zugeordneten Belege. Ein Agent ordnet sie zu, sobald er sie erfasst hat.'));
    }

    return fragment;
}

function defaultEmpty() {
    const empty = element('p', 'Noch keine Belege. Lege im ');
    empty.appendChild(link('Eingang', '/garage/eingang.html'));
    empty.appendChild(document.createTextNode(' ein Foto oder ein PDF ab.'));

    return empty;
}

function toolbar(visible, view, onChange) {
    const bar = element('div', undefined, 'tb');
    const openCount = visible.filter((entry) => entry.status !== 'zugeordnet').length;
    bar.appendChild(element('span', visible.length + (visible.length === 1 ? ' Beleg' : ' Belege') + ' · ' + openCount + ' offen', 'muted small'));
    bar.appendChild(viewSwitch(STORAGE_KEY, [
        { value: 'gal', label: 'Galerie', icon: icons.photo },
        { value: 'list', label: 'Liste', icon: icons.list },
    ], view, onChange));

    return bar;
}

/**
 * Belege in `container`, Umschalter Galerie/Liste in der Werkzeugleiste (`garage.view.belege`).
 * `options`: `vehicleId` (nur dieses Fahrzeug, sonst alle), `detailBase` (Präfix der Detail-Adresse, leer auf `belege.html`),
 * `vehicles` (`[{id, label}]` für die Fahrzeugangabe in der Liste), `defaultView` (`'list'` oder `'gal'`), `renderEmpty()` (eigener Leerzustand).
 */
export async function renderDocuments(container, options = {}) {
    const settings = {
        vehicleId: options.vehicleId ?? null,
        detailBase: options.detailBase ?? '',
        vehicleNames: new Map((options.vehicles ?? []).map((vehicle) => [vehicle.id, vehicle.label])),
        showVehicle: options.vehicleId === undefined || options.vehicleId === null,
    };
    const renderEmpty = options.renderEmpty ?? defaultEmpty;
    const defaultView = options.defaultView ?? 'list';
    const path = settings.vehicleId === null ? 'documents' : 'documents?vehicle_id=' + settings.vehicleId;

    async function load() {
        clearChildren(container);
        let documents;
        try {
            documents = (await request('GET', path)).documents;
        } catch (error) {
            container.appendChild(errorBlock(error.message, load));

            return;
        }
        draw(documents);
    }

    function draw(documents) {
        clearChildren(container);
        if (documents.length === 0) {
            container.appendChild(renderEmpty());

            return;
        }
        const view = readView(STORAGE_KEY, VIEWS, defaultView);
        container.appendChild(toolbar(documents, view, () => draw(documents)));
        container.appendChild(view === 'gal' ? renderGallery(documents, settings) : renderList(documents, settings));
    }

    await load();
}
