// PDF-Seiten als JPEG rendern: Agenten lesen die Seitenbilder, das Original bleibt unverändert (ADR-014).
import { canvasToJpeg } from './image.js';

const PDFJS_URL = '/garage/vendor/pdfjs/pdf.min.mjs';
const WORKER_URL = '/garage/vendor/pdfjs/pdf.worker.min.mjs';
const MAX_PAGE_BYTES = 4 * 1024 * 1024;
const FALLBACK_QUALITY = 0.6;

let pdfjsModule = null;

// Erst beim ersten PDF laden: Fotos und die Belegliste brauchen die 350 KB nicht.
async function loadPdfjs() {
    if (pdfjsModule === null) {
        pdfjsModule = await import(PDFJS_URL);
        pdfjsModule.GlobalWorkerOptions.workerSrc = WORKER_URL;
    }

    return pdfjsModule;
}

async function renderPage(pdf, pageNumber, maxEdge) {
    const page = await pdf.getPage(pageNumber);
    const baseSize = page.getViewport({ scale: 1 });
    const viewport = page.getViewport({ scale: maxEdge / Math.max(baseSize.width, baseSize.height) });
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(viewport.width);
    canvas.height = Math.round(viewport.height);
    const context = canvas.getContext('2d');
    // JPEG kennt keine Transparenz: ohne weißen Grund würden leere PDF-Flächen schwarz.
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    await page.render({ canvasContext: context, viewport }).promise;
    page.cleanup();

    let blob = await canvasToJpeg(canvas);
    if (blob.size > MAX_PAGE_BYTES) {
        blob = await canvasToJpeg(canvas, FALLBACK_QUALITY);
    }

    return blob;
}

/**
 * Rendert jede Seite auf die längste Kante `maxEdge`. Mehr als `maxPages` Seiten ergeben einen Fehler mit verständlichem Text.
 * `onProgress(seite, gesamt)` wird vor jeder Seite gerufen.
 */
export async function renderPages(file, maxEdge = 1568, maxPages = 20, onProgress = () => {}) {
    const pdfjs = await loadPdfjs();
    const data = new Uint8Array(await file.arrayBuffer());

    let pdf;
    try {
        // isEvalSupported: false — die CSP erlaubt kein eval, pdf.js nutzt dann den sicheren Pfad.
        pdf = await pdfjs.getDocument({ data, isEvalSupported: false }).promise;
    } catch {
        throw new Error('„' + file.name + '“ lässt sich nicht als PDF öffnen. Ist die Datei beschädigt oder passwortgeschützt?');
    }

    try {
        if (pdf.numPages > maxPages) {
            throw new Error('„' + file.name + '“ hat ' + pdf.numPages + ' Seiten. Es gehen höchstens ' + maxPages + ' Seiten je PDF; teile die Datei auf.');
        }
        const blobs = [];
        for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
            onProgress(pageNumber, pdf.numPages);
            blobs.push(await renderPage(pdf, pageNumber, maxEdge));
        }

        return blobs;
    } finally {
        await pdf.destroy();
    }
}
