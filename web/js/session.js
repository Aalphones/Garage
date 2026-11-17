// Gemeinsamer Einstieg von Eingang und Belege: Sitzung wiederherstellen, Garagen und Fahrzeuge laden.
import { request, restore } from './api.js';
import { clearChildren, element, link } from './dom.js';

const READONLY_ROLE = 'lesend';

/** Gibt den Nutzer zurück oder `null` und erklärt in `statusLine`, was fehlt. */
export async function requireSession(statusLine) {
    let user = null;
    try {
        user = await restore();
    } catch (error) {
        statusLine.textContent = error.message;
        return null;
    }
    if (user === null) {
        clearChildren(statusLine);
        statusLine.appendChild(element('span', 'Du bist nicht angemeldet. '));
        statusLine.appendChild(link('Zur Anmeldung', '/garage/index.html'));

        return null;
    }
    statusLine.textContent = '';

    return user;
}

/** Garagen mit Rolle und eine flache Fahrzeugliste, in der jedes Fahrzeug seine Garage kennt. */
export async function loadContext() {
    const garages = (await request('GET', 'vehicles')).garages;
    const vehicles = [];
    for (const garage of garages) {
        for (const vehicle of garage.vehicles) {
            vehicles.push({ id: vehicle.id, label: vehicle.label, garageId: garage.id, garageName: garage.name, canWrite: garage.role !== READONLY_ROLE });
        }
    }

    return {
        garages,
        vehicles,
        writableGarages: garages.filter((garage) => garage.role !== READONLY_ROLE),
    };
}
