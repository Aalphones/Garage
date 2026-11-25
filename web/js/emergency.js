// Notfallkarte im Gerätecache (ADR-025): geschrieben nach jedem Laden mit Netz, gelesen von notfall.html ohne Anmeldung und ohne API.
// Gehört zur Hülle des Service Workers — darf außer dom.js nichts importieren, sonst lädt notfall.html offline nicht.
import { element, formatDay } from './dom.js';

const EMERGENCY_CACHE = 'garage-notfall';
const ENTRY_PREFIX = '/garage/notfall/';
const ENTRY_SUFFIX = '.json';
const JSON_HEADERS = { 'Content-Type': 'application/json' };

function hasCacheApi() {
    return window.caches !== undefined;
}

/** Legt die Karte eines Fahrzeugs ab; `false`, wenn das Gerät keinen Cache anbietet oder das Schreiben scheitert. */
export async function storeEmergency(emergency) {
    if (!hasCacheApi()) {
        return false;
    }
    try {
        const cache = await caches.open(EMERGENCY_CACHE);
        await cache.put(ENTRY_PREFIX + emergency.vehicle_id + ENTRY_SUFFIX, new Response(JSON.stringify(emergency), { headers: JSON_HEADERS }));
    } catch {
        return false;
    }

    return true;
}

/** Alle gespeicherten Karten, nach Fahrzeugname sortiert; leere Liste, wenn nichts gespeichert ist. */
export async function readEmergencies() {
    if (!hasCacheApi() || !(await caches.has(EMERGENCY_CACHE))) {
        return [];
    }
    const cache = await caches.open(EMERGENCY_CACHE);
    const requests = await cache.keys();
    const cards = await Promise.all(requests.map(async (request) => (await cache.match(request)).json()));

    return cards.sort((first, second) => first.label.localeCompare(second.label, 'de'));
}

/** Beim Abmelden: die Karten sind Daten des Kontos und bleiben nicht auf dem Gerät. */
export async function clearEmergencies() {
    if (!hasCacheApi()) {
        return;
    }
    try {
        await caches.delete(EMERGENCY_CACHE);
    } catch {
        // Ohne Cache-Zugriff gibt es auch nichts zu löschen.
    }
}

function joined(parts) {
    return parts.filter((part) => part !== null && part !== undefined && part !== '').join(' · ');
}

function phoneLine(number) {
    const line = element('div');
    const anchor = element('a', number);
    anchor.href = 'tel:' + number.replace(/[^+\d]/g, '');
    line.appendChild(anchor);

    return line;
}

function entry(list, term, text, phone) {
    list.appendChild(element('dt', term));
    const definition = element('dd');
    definition.appendChild(text === null ? element('span', 'nicht erfasst', 'muted') : document.createTextNode(text));
    if (phone !== null && phone !== undefined) {
        definition.appendChild(phoneLine(phone));
    }
    list.appendChild(definition);
}

/** Karte `.ecard` aus der Antwort von `GET vehicles/{id}/emergency`; der Kopf `.hd` nimmt Zustandswörter auf. */
export function emergencyCard(emergency) {
    const card = element('div', undefined, 'ecard');
    const head = element('div', undefined, 'hd');
    head.appendChild(element('b', emergency.label));
    card.appendChild(head);

    const list = element('dl');
    entry(list, 'Kennzeichen', emergency.plate, null);
    entry(list, 'FIN', emergency.fin, null);
    const { insurer, breakdown } = emergency;
    if (insurer === null) {
        list.appendChild(element('dt', 'Versicherer'));
        const definition = element('dd');
        definition.appendChild(element('span', 'fehlt — Police im Chat an GARAGE geben', 'gap'));
        list.appendChild(definition);
    } else {
        entry(list, 'Versicherer', joined([insurer.provider, insurer.contract_number]), insurer.hotline);
    }
    if (breakdown === null) {
        entry(list, 'Pannenhilfe', null, null);
    } else {
        const member = breakdown.member_number === null ? null : 'Mitglied ' + breakdown.member_number;
        entry(list, 'Pannenhilfe', joined([breakdown.provider, member]), breakdown.hotline);
    }
    card.append(list, element('div', 'Stand ' + formatDay(emergency.as_of), 'muted small'));

    return card;
}
