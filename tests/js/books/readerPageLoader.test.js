// Carga protegida de páginas del lector. Correr con: npm run test:js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    codeFor,
    createPageLoader,
    noticeFor,
    READER_NOTICES,
} from '../../../resources/js/components/books/readerPageLoader.js';

function fakeUrlApi() {
    let next = 0;
    const revoked = [];
    return {
        revoked,
        createObjectURL: () => `blob:pagina-${++next}`,
        revokeObjectURL: (src) => revoked.push(src),
    };
}

function imageResponse(remaining = '120') {
    const headers = new Headers({ 'Content-Type': 'image/webp' });
    if (remaining !== null) headers.set('X-Paginas-Restantes', remaining);
    return new Response(new Uint8Array([82, 73, 70, 70]), { status: 200, headers });
}

function jsonResponse(status, body) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

test('entrega la página como blob y lee las páginas que quedan hoy', async () => {
    const urlApi = fakeUrlApi();
    const calls = [];
    const loader = createPageLoader({
        urlApi,
        fetchImpl: async (url, options) => {
            calls.push({ url, options });
            return imageResponse('25');
        },
    });

    const result = await loader.load('/biblioteca/ediciones/1/paginas/3');

    assert.equal(result.ok, true);
    assert.equal(result.src, 'blob:pagina-1');
    assert.equal(result.remaining, 25);
    assert.equal(calls[0].options.credentials, 'same-origin');
    assert.match(calls[0].options.headers.Accept, /^application\/json/);
});

test('volver a una página guardada no la pide de nuevo', async () => {
    let fetches = 0;
    const loader = createPageLoader({ urlApi: fakeUrlApi(), fetchImpl: async () => (fetches++, imageResponse()) });

    await loader.load('/p/1');
    const again = await loader.load('/p/1');

    assert.equal(fetches, 1);
    assert.equal(again.fromCache, true);
    assert.equal(again.remaining, null);
});

test('guarda solo las últimas páginas y libera las demás', async () => {
    const urlApi = fakeUrlApi();
    const loader = createPageLoader({ urlApi, cacheSize: 2, fetchImpl: async () => imageResponse() });

    await loader.load('/p/1');
    await loader.load('/p/2');
    await loader.load('/p/3');

    assert.equal(loader.size, 2);
    assert.deepEqual(urlApi.revoked, ['blob:pagina-1']);

    loader.clear();
    assert.equal(loader.size, 0);
    assert.deepEqual(urlApi.revoked, ['blob:pagina-1', 'blob:pagina-2', 'blob:pagina-3']);
});

test('cada código del servidor se convierte en su aviso, con el mensaje del servidor', async () => {
    const cases = [
        [409, 'otro_dispositivo', 'claim'],
        [429, 'limite_diario', 'close'],
        [423, 'lectura_pausada', 'close'],
        [429, 'muy_rapido', 'retry'],
        [429, 'ritmo_rapido', 'retry'],
        [503, 'lector_detenido', 'retry'],
        [403, 'fuera_del_lector', 'retry'],
    ];

    for (const [status, code, action] of cases) {
        const loader = createPageLoader({
            urlApi: fakeUrlApi(),
            fetchImpl: async () => jsonResponse(status, { code, message: `Mensaje de ${code}`, retry_at_text: '20 nov 2026, 00:00' }),
        });
        const result = await loader.load('/p/9');

        assert.equal(result.ok, false);
        assert.equal(result.code, code);
        assert.equal(result.action, action);
        assert.equal(result.message, `Mensaje de ${code}`);
        assert.equal(result.retryAtText, '20 nov 2026, 00:00');
    }
});

test('la página pasada la muestra abre la cortina de membresía, no un aviso', async () => {
    const loader = createPageLoader({ urlApi: fakeUrlApi(), fetchImpl: async () => jsonResponse(403, { code: 'membresia', message: 'x' }) });
    const result = await loader.load('/p/50');

    assert.equal(result.code, 'membresia');
    assert.equal(READER_NOTICES.membresia, undefined);
});

test('sin código, se deduce del estado; sin conexión y sesión vencida tienen su aviso', async () => {
    assert.equal(codeFor(419, undefined), 'sesion_vencida');
    assert.equal(codeFor(401, undefined), 'sesion_vencida');
    assert.equal(codeFor(500, undefined), 'error');
    assert.equal(codeFor(409, 'codigo_raro'), 'otro_dispositivo');
    assert.equal(codeFor(502, undefined), 'servidor_ocupado');
    assert.equal(codeFor(503, undefined), 'servidor_ocupado');
    assert.equal(codeFor(504, undefined), 'servidor_ocupado');

    const offline = createPageLoader({ urlApi: fakeUrlApi(), fetchImpl: async () => { throw new TypeError('Failed to fetch'); } });
    assert.equal((await offline.load('/p/1')).code, 'sin_conexion');

    const loginPage = createPageLoader({
        urlApi: fakeUrlApi(),
        fetchImpl: async () => new Response('<html>Iniciar sesión</html>', { status: 200, headers: { 'Content-Type': 'text/html' } }),
    });
    assert.equal((await loginPage.load('/p/1')).code, 'sesion_vencida');
});

test('un pedido cancelado (cambio rápido de página) no muestra aviso', async () => {
    const loader = createPageLoader({
        urlApi: fakeUrlApi(),
        fetchImpl: async () => { throw new DOMException('cancelado', 'AbortError'); },
    });

    assert.deepEqual(await loader.load('/p/1'), { ok: false, aborted: true });
});

test('un 503 del hosting (sin código del lector) no promete que se avisó al administrador', async () => {
    const loader = createPageLoader({
        urlApi: fakeUrlApi(),
        fetchImpl: async () => new Response('<html>503 Service Unavailable</html>', { status: 503, headers: { 'Content-Type': 'text/html' } }),
    });
    const result = await loader.load('/p/3');

    assert.equal(result.code, 'servidor_ocupado');
    assert.equal(result.action, 'retry');
    assert.doesNotMatch(`${result.title} ${result.message}`, /avis/i);
    assert.equal(result.detail, null);
});

test('el motivo técnico que el servidor manda a administradores llega al aviso', async () => {
    const withDetail = createPageLoader({
        urlApi: fakeUrlApi(),
        fetchImpl: async () =>
            jsonResponse(503, { code: 'lector_detenido', message: 'No se pudo marcar.', detail: 'La extensión GD de PHP no tiene soporte WebP.' }),
    });
    const notice = await withDetail.load('/p/3');
    assert.equal(notice.code, 'lector_detenido');
    assert.equal(notice.detail, 'La extensión GD de PHP no tiene soporte WebP.');

    // A un estudiante el servidor no le manda motivo: el aviso no tiene detalle.
    const withoutDetail = createPageLoader({
        urlApi: fakeUrlApi(),
        fetchImpl: async () => jsonResponse(503, { code: 'lector_detenido', message: 'Ya avisamos al administrador.' }),
    });
    assert.equal((await withoutDetail.load('/p/3')).detail, null);
});

test('sin mensaje del servidor se usa el texto propio del aviso', () => {
    const notice = noticeFor('limite_diario', '');
    assert.equal(notice.message, READER_NOTICES.limite_diario.message);
    assert.equal(notice.actionLabel, 'Volver a la biblioteca');
});
