// Löschen eines Datensatzes aus den Reitern: sofort, danach Leiste mit „Rückgängig“ (ADR-019).
import { ApiError, request } from './api.js';
import { toast } from './toast.js';

function failureText(error, fallback) {
    return error instanceof ApiError ? error.message : fallback;
}

/**
 * `type`: Datensatzart der Route `records/{type}/…`; `noun`: Wort für die Leiste („Messung“); `reload()` baut den Reiter neu.
 * Gibt `true` zurück, wenn gelöscht wurde.
 */
export async function deleteRecord(type, id, noun, reload) {
    try {
        await request('POST', 'records/' + type + '/' + id + '/delete', {});
    } catch (error) {
        toast(failureText(error, noun + ' konnte nicht gelöscht werden.'));

        return false;
    }
    reload();
    toast(noun + ' gelöscht', {
        undo: async () => {
            try {
                await request('POST', 'records/' + type + '/' + id + '/restore', {});
            } catch (error) {
                toast(failureText(error, noun + ' konnte nicht wiederhergestellt werden.'));

                return;
            }
            reload();
        },
    });

    return true;
}
