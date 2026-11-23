// Setzt das Thema, bevor die Seite sichtbar wird: Modul im <head>, kein Inline-Skript (CSP).
const STORAGE_KEY = 'garage.theme';
const MODES = ['dark', 'light', 'system'];
const DEFAULT_MODE = 'dark';
const DARK_QUERY = '(prefers-color-scheme: dark)';

export function storedMode() {
    let mode = null;
    try {
        mode = localStorage.getItem(STORAGE_KEY);
    } catch {
        // Speicher gesperrt: es gilt der Standard.
    }

    return MODES.includes(mode) ? mode : DEFAULT_MODE;
}

function resolve(mode) {
    if (mode === 'system') {
        return window.matchMedia(DARK_QUERY).matches ? 'dark' : 'light';
    }

    return mode;
}

export function applyTheme(mode) {
    document.documentElement.dataset.theme = resolve(mode);
}

export function setMode(mode) {
    try {
        localStorage.setItem(STORAGE_KEY, mode);
    } catch {
        // Ohne Speicher gilt die Wahl nur bis zum Neuladen.
    }
    applyTheme(mode);
}

applyTheme(storedMode());
window.matchMedia(DARK_QUERY).addEventListener('change', () => {
    if (storedMode() === 'system') {
        applyTheme('system');
    }
});
