/**
 * Procesador en cliente de PDF a WebP y optimizador de portadas.
 * Carga `pdfjs-dist` bajo demanda (lazy chunk) para mantener ligero el bundle público.
 */

let pdfjsLoader = null;

async function getPdfJs() {
    if (!pdfjsLoader) {
        pdfjsLoader = Promise.all([
            import('pdfjs-dist'),
            import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
        ]).then(([pdfjsLib, workerModule]) => {
            pdfjsLib.GlobalWorkerOptions.workerSrc = workerModule.default;
            return pdfjsLib;
        });
    }

    return pdfjsLoader;
}

function canvasToWebpBlob(canvas, quality = 0.82) {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => {
                if (blob && blob.type === 'image/webp') {
                    resolve(blob);
                } else {
                    reject(new Error('El navegador no pudo generar las imágenes optimizadas.'));
                }
            },
            'image/webp',
            quality,
        );
    });
}

export async function inspectPdfFile(file) {
    if (!file) {
        throw new Error('Selecciona un archivo PDF válido.');
    }

    const header = await file.slice(0, 5).text();
    if (!header.startsWith('%PDF-')) {
        throw new Error('El archivo seleccionado no tiene estructura PDF válida.');
    }

    const pdfjsLib = await getPdfJs();
    const buffer = await file.arrayBuffer();
    const pdfDoc = await pdfjsLib.getDocument({ data: buffer }).promise;

    try {
        const pageCount = pdfDoc.numPages;
        if (!pageCount || pageCount < 1) {
            throw new Error('El documento PDF no contiene páginas legibles.');
        }
        if (pageCount > 2000) {
            throw new Error('El documento supera el máximo de 2000 páginas por edición.');
        }

        return { pageCount };
    } finally {
        await pdfDoc.destroy();
    }
}

export async function convertAndUploadPdfBatches({
    file,
    uploadBatch,
    batchSize = 5,
    targetWidth = 1200,
    quality = 0.82,
    signal,
    onProgress,
}) {
    const pdfjsLib = await getPdfJs();
    const buffer = await file.arrayBuffer();
    const pdfDoc = await pdfjsLib.getDocument({ data: buffer }).promise;

    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { alpha: false });
    let optimizedBytes = 0;

    try {
        const totalPages = pdfDoc.numPages;
        let batch = [];
        let isFirstBatch = true;

        for (let pageNumber = 1; pageNumber <= totalPages; pageNumber += 1) {
            if (signal?.aborted) {
                throw new DOMException('Cancelado por el usuario.', 'AbortError');
            }

            onProgress?.({
                phase: 'converting',
                currentPage: pageNumber,
                totalPages,
                optimizedBytes,
            });

            const page = await pdfDoc.getPage(pageNumber);
            const baseViewport = page.getViewport({ scale: 1 });
            const scale = Math.min(2.2, targetWidth / Math.max(1, baseViewport.width));
            const viewport = page.getViewport({ scale });

            canvas.width = Math.max(1, Math.ceil(viewport.width));
            canvas.height = Math.max(1, Math.ceil(viewport.height));

            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            await page.render({
                canvasContext: ctx,
                viewport,
                intent: 'display',
            }).promise;
            page.cleanup();

            const blob = await canvasToWebpBlob(canvas, quality);
            optimizedBytes += blob.size;
            batch.push({ number: pageNumber, blob });

            const isLastPage = pageNumber === totalPages;

            if (batch.length >= batchSize || isLastPage) {
                onProgress?.({
                    phase: 'uploading',
                    currentPage: pageNumber,
                    totalPages,
                    optimizedBytes,
                });

                const payload = {
                    pages: batch,
                    totalPages,
                    isFirstBatch,
                    isFinalBatch: isLastPage,
                    signal,
                };

                try {
                    await uploadBatch(payload);
                } catch (err) {
                    const status = err?.response?.status;
                    if (signal?.aborted || err?.name === 'AbortError' || err?.code === 'ERR_CANCELED' || (status >= 400 && status < 500)) {
                        throw err;
                    }
                    await new Promise((r) => setTimeout(r, 800));
                    await uploadBatch(payload);
                }

                batch = [];
                isFirstBatch = false;
            }
        }

        return { totalPages, optimizedBytes };
    } finally {
        canvas.width = 0;
        canvas.height = 0;
        await pdfDoc.destroy();
    }
}

export async function optimizeCoverImage(file, maxWidth = 800, quality = 0.86) {
    if (!file || !file.type.startsWith('image/')) return file;

    return new Promise((resolve) => {
        const objectUrl = URL.createObjectURL(file);
        const img = new Image();

        img.onload = async () => {
            URL.revokeObjectURL(objectUrl);
            const ratio = img.width > maxWidth ? maxWidth / img.width : 1;
            const width = Math.max(1, Math.round(img.width * ratio));
            const height = Math.max(1, Math.round(img.height * ratio));

            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            canvas.getContext('2d').drawImage(img, 0, 0, width, height);

            try {
                const blob = await canvasToWebpBlob(canvas, quality);
                canvas.width = 0;
                canvas.height = 0;

                if (blob.size < file.size || file.type !== 'image/webp') {
                    const baseName = (file.name || 'portada').replace(/\.[^.]+$/, '');
                    resolve(new File([blob], `${baseName}.webp`, { type: 'image/webp' }));
                    return;
                }
            } catch {
                // Mantiene archivo original si el navegador no codifica WebP
            }

            canvas.width = 0;
            canvas.height = 0;
            resolve(file);
        };

        img.onerror = () => {
            URL.revokeObjectURL(objectUrl);
            resolve(file);
        };

        img.src = objectUrl;
    });
}

export function formatBytesHuman(bytes) {
    if (!bytes || bytes <= 0) return '0 KB';
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

