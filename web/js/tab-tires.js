// Reiter „Reifen“: eine Karte je Reifensatz, der montierte mit Teal-Rand.
import { request } from './api.js';
import { actionButton, clearChildren, element, formatDay } from './dom.js';
import * as icons from './icons.js';
import { badge } from './provenance.js';
import { deleteRecord } from './record-delete.js';
import { emptyBlock, errorBlock, formatKm, stateWord } from './widgets.js';

const OLD_TIRE_YEARS = 6;
const SEASON_LABELS = { sommer: 'Sommer', winter: 'Winter', ganzjahr: 'Ganzjahr' };

/** Der flachste der zuletzt gemessenen Reifen; `null`, wenn es keine Profiltiefe gibt. */
function shallowestTread(series) {
    let shallowest = null;
    for (const entry of series) {
        if (entry.quantity !== 'profiltiefe' || entry.points.length === 0) {
            continue;
        }
        const latest = entry.points[entry.points.length - 1];
        if (shallowest === null || latest.value < shallowest.value) {
            shallowest = { value: latest.value, date: latest.date };
        }
    }

    return shallowest;
}

function addFact(list, term, value) {
    list.appendChild(element('dt', term));
    const definition = element('dd');
    if (value instanceof Node) {
        definition.appendChild(value);
    } else {
        definition.textContent = value;
    }
    list.appendChild(definition);
}

function ageCell(set) {
    if (set.dot === null) {
        return 'kein DOT erfasst';
    }
    const cell = element('span', set.dot + ' · ' + set.dot_age_years + (set.dot_age_years === 1 ? ' Jahr ' : ' Jahre '));
    if (set.dot_age_years >= OLD_TIRE_YEARS) {
        cell.appendChild(stateWord('st warn', 'alt'));
    }

    return cell;
}

function sinceText(mount) {
    let text = formatDay(mount.mounted_on);
    if (mount.mounted_km !== null) {
        text += ' · ' + formatKm(mount.mounted_km) + ' km';
    }

    return text;
}

function tireName(set) {
    if (set.brand === null) {
        return 'unbekannt';
    }
    if (set.model === null) {
        return set.brand;
    }

    return set.brand + ' ' + set.model;
}

/** `tread`: jüngste Profiltiefe, nur für den montierten Satz — Messwerte gehören zum Fahrzeug, nicht zu einem Satz. */
function tireFacts(set, openMount, tread) {
    const facts = element('dl');
    addFact(facts, 'Reifen', tireName(set));
    addFact(facts, 'Größe', set.size + ' · ' + (SEASON_LABELS[set.season] ?? set.season));
    addFact(facts, 'DOT', ageCell(set));
    if (tread !== null) {
        addFact(facts, 'Profil', 'ab ' + tread.value.toLocaleString('de-DE', { maximumFractionDigits: 2 }) + ' mm · ' + formatDay(tread.date));
    }
    if (openMount !== undefined) {
        addFact(facts, 'Seit', sinceText(openMount));
    } else if (set.storage_location !== null) {
        addFact(facts, 'Lager', set.storage_location);
    }

    return facts;
}

function tireCard(context, set, isMounted, tread) {
    const card = element('div', undefined, isMounted ? 'tire on' : 'tire');
    const head = element('div', undefined, 'hd');
    head.appendChild(element('b', set.label));
    head.appendChild(stateWord('st ok', isMounted ? 'montiert' : 'eingelagert'));
    card.appendChild(head);
    const openMount = set.mounts.find((mount) => mount.removed_on === null);
    card.appendChild(tireFacts(set, openMount, isMounted ? tread : null));

    const foot = element('div', undefined, 'actions');
    foot.appendChild(badge(set.source, set.confidence, {}));
    if (context.vehicle.permissions.write) {
        foot.appendChild(actionButton('Satz löschen', 'btn sm danger', () => deleteRecord('tire_set', set.id, 'Reifensatz', context.reload)));
    }
    card.appendChild(foot);

    return card;
}

/** `context`: `{ vehicleId, vehicle, reload() }` wie beim Reiter Übersicht. */
export async function renderTires(container, context) {
    let tires;
    let measurements;
    try {
        [tires, measurements] = await Promise.all([
            request('GET', 'vehicles/' + context.vehicleId + '/tires'),
            request('GET', 'vehicles/' + context.vehicleId + '/measurements'),
        ]);
    } catch (error) {
        clearChildren(container);
        container.appendChild(errorBlock(error.message, context.reload));

        return;
    }
    clearChildren(container);
    if (tires.sets.length === 0) {
        container.appendChild(emptyBlock(icons.car, 'Noch keine Reifensätze', 'Fotografier die Reifenflanke mit DOT und gib das Bild deinem Agenten.'));

        return;
    }
    const tread = shallowestTread(measurements.series);
    const grid = element('div', undefined, 'tires');
    for (const set of tires.sets) {
        grid.appendChild(tireCard(context, set, set.id === tires.mounted_set_id, tread));
    }
    container.appendChild(grid);
}
