// Bilder im Browser verkleinern, bevor sie hochgeladen werden (Handyfotos haben leicht 10 MB und mehr).
const JPEG_QUALITY = 0.85;

export function canvasToJpeg(canvas, quality = JPEG_QUALITY) {
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (blob === null) {
                reject(new Error('Das Bild lässt sich nicht umwandeln.'));
                return;
            }
            resolve(blob);
        }, 'image/jpeg', quality);
    });
}

/**
 * Verkleinert ein Foto auf die längste Kante `maxEdge` als JPEG. Ein kleineres Bild bleibt unverändert.
 * `imageOrientation: 'from-image'` dreht nach der EXIF-Angabe, bevor sie beim Neukodieren verloren geht.
 */
export async function shrinkPhoto(file, maxEdge) {
    let bitmap;
    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        throw new Error('„' + file.name + '“ lässt sich nicht lesen. Fotos im Format HEIC bitte als JPEG aufnehmen oder speichern.');
    }

    const longestEdge = Math.max(bitmap.width, bitmap.height);
    if (longestEdge <= maxEdge) {
        bitmap.close();

        return { blob: file, wasResized: false };
    }

    const scale = maxEdge / longestEdge;
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    return { blob: await canvasToJpeg(canvas), wasResized: true };
}
