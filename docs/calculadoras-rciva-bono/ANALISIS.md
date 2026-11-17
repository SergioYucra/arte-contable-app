# Calculadoras RC-IVA y Bono de antigüedad

Análisis de las páginas de boliviaimpuestos.com que tomamos de modelo, y cómo las copiamos.
Revisado el 17/11/2026.

- RC-IVA: https://boliviaimpuestos.com/maletin/rc-iva.php
- Bono de antigüedad: https://boliviaimpuestos.com/maletin/bono-antiguedad.php

## 1. No tienen API: calculan en la página

A diferencia de la calculadora de alivio tributario (que llama a `fap.php` y `deudas.php`),
estas dos páginas **no consultan a ningún servidor para calcular**. Todo el cálculo está en un
`<script>` de la propia página y se recalcula al escribir. Los únicos `fetch` que hacen son para
comentarios y la suscripción al boletín.

Por eso no se puede "consumir su API". Lo que se hizo:

1. **Copiar sus fórmulas tal cual** en nuestro código, sin "mejorarlas", para dar los mismos montos.
2. **Probarlas contra su página real**: 12 casos de RC-IVA y 19 del bono, capturados llenando sus
   campos con Playwright (`tests/js/fixtures/`). Nuestras fórmulas dan lo mismo al centavo.
3. **Revisión diaria** (`php artisan calculators:check`): descarga sus dos páginas, lee sus tasas y
   tablas **sin ejecutar su código**, y avisa al desarrollador líder si cambian.

Importante: aquí **no** seguimos sus cambios solos, como en el PR 3. Si ellos cambian algo, llega
un correo y alguien actualiza `parametros.json`.

## 2. Fórmula del RC-IVA (un mes, persona con sueldo)

Datos: total ganado `g`, salario mínimo `SMN`, saldo a favor del mes anterior `sf`, facturas `fac`.

| Paso | Fórmula | En su script |
|---|---|---|
| Aporte AFP | `g × 12,71%` | `afp=g*0.1271` |
| Aporte Nacional Solidario | `(g − 13.000, máx. 12.000) × 1,15%` + `(g − 25.000, máx. 10.000) × 5,74%` + `(g − 35.000) × 11,48%` | función `ans(s)` |
| Sueldo neto | `g − AFP − ANS` | `neto` |
| Base imponible | `máx(0, neto − 2 SMN)` | `mni=smn*2` |
| Impuesto | `base × 13%` | `imp=base*0.13` |
| Descuento automático | `SMN × 13%` (**1** salario mínimo) | `compAuto=smn*0.13` |
| RC-IVA determinado | `máx(0, impuesto − descuento)` | `sinComp` |
| Crédito por facturas | `fac × 13%` | `compFac=fac*0.13` |
| Saldo a pagar | `máx(0, determinado − sf − crédito)` | `saldo` |
| Facturas que faltan | `saldo ÷ 0,13` | `facNec` |

No redondean los pasos intermedios; solo al mostrar (2 decimales, `es-BO`). Nosotros igual.

**Para validar con un contador:** su página descuenta el 13% de **un** salario mínimo. La planilla
tributaria clásica muestra el "13% de dos salarios mínimos". Lo copiamos como lo tienen ellos
(decisión del equipo: seguir su cálculo), pero conviene confirmarlo con Max o Sergio.

Su página trae por defecto el salario mínimo de 2025 (Bs 2.750); la nuestra trae el más reciente
de la tabla (2026: Bs 3.300, DS 5516). Con el mismo salario mínimo dan exactamente lo mismo.

## 3. Fórmula del bono de antigüedad

Escala del DS 21060 art. 60:

| Años cumplidos | % |
|---|---|
| 2 a 4 | 5 |
| 5 a 7 | 11 |
| 8 a 10 | 18 |
| 11 a 14 | 26 |
| 15 a 19 | 34 |
| 20 a 24 | 42 |
| 25 o más | 50 |

- **Antigüedad:** años completos entre la fecha de ingreso y la del cálculo (si el aniversario no
  llegó, no cuenta). Sumar años a un 29 de febrero cae en 1 de marzo en años no bisiestos, igual
  que en su página. También se pueden escribir los años directamente.
- **Base:** `SMN × 3` (regla general, DS 23474), `SMN × 1` (ONG, DS 21137 art. 13) u otra cifra
  de convenio.
- **Bono:** `base × %`; proporcional a días: `bono × días ÷ 30`.
- **Total ganado** (opcional): haber básico + bono.
- **Próximo tramo:** fecha en que llega, cuánto sube y avance (porcentaje del tiempo recorrido
  entre el inicio del tramo actual y el siguiente).
- **Reliquidación:** `bono con SMN anterior = SMN anterior × base × %`; diferencia mensual =
  bono nuevo (mes completo) − bono anterior; retroactivo = diferencia × meses.

Tabla de salarios mínimos que traen (2015 a 2026, con decreto): la misma de `parametros.json`.

Una mejora de texto que **no** cambia montos: su página dice "en 1 año" cuando falta un solo día
para el próximo tramo (resta años, no fechas). La nuestra dice "mañana", "en 54 días", "en 10 meses".

## 4. Dónde está cada cosa

| Qué | Dónde |
|---|---|
| Tasas, tablas y salarios mínimos (única fuente) | `resources/js/components/calculators/parametros.json` |
| Fórmulas (funciones puras, sin Vue) | `resources/js/components/calculators/rcIva.js`, `bonoAntiguedad.js` |
| Pantallas | `RcIvaCalculator.vue`, `BonoAntiguedadCalculator.vue` (registradas en `StudentCalculatorsCatalog.vue`) |
| Pruebas de fórmulas contra su página | `tests/js/calculators/*.test.js` + `tests/js/fixtures/*.json` (`npm run test:js`) |
| Revisión diaria | `app/Modules/Calculators` (`php artisan calculators:check`) y `config/calculators.php` |
| Pruebas de la revisión | `tests/Feature/Calculators` + `tests/Fixtures/calculators/*.html` (copias recortadas de sus páginas) |
| Script que capturó los casos | `capturar-casos.cjs` |

## 5. Cuando llega el aviso "boliviaimpuestos cambió su cálculo"

1. Abrir su página y ver qué cambió (el correo lo dice en palabras: "Agregaron el salario mínimo
   de 2027: Bs …", "Aporte a la AFP: usamos 12,71% y su página ahora usa …").
2. Actualizar `parametros.json`. Si cambió la fórmula (no solo un número), ajustar `rcIva.js` o
   `bonoAntiguedad.js`.
3. Volver a capturar los casos y correr las pruebas:
   ```
   PLAYWRIGHT_CORE=<ruta a playwright-core> node docs/calculadoras-rciva-bono/capturar-casos.cjs tests/js/fixtures
   npm run test:js
   ```
4. Guardar la huella nueva: `php artisan calculators:check --show-fingerprint` y copiarla en
   `config/calculators.php` (`sources.*.fingerprint`). Actualizar también
   `tests/Fixtures/calculators/*.html` con la página nueva.

   Si solo cambiaron textos de su página (no la fórmula), basta con poner la huella nueva en
   `.env` (`CALCULATORS_RC_IVA_FINGERPRINT` o `CALCULATORS_BONO_FINGERPRINT`) y correr
   `php artisan config:clear`, sin desplegar.

El mismo aviso se repite como máximo una vez cada 7 días mientras no cambie su contenido
(`monitor.repeat_after_days`).
5. `npm run build` y desplegar.

**Cada año (mayo, aumento salarial):** cuando sale el decreto del nuevo salario mínimo,
boliviaimpuestos lo agrega a su tabla y llega el aviso. Agregar la fila en `salariosMinimos`
(la primera es la que aparece por defecto).

## 6. Si la revisión dice "no se pudo revisar"

Las calculadoras de los estudiantes no se afectan: calculan en el navegador. Solo significa que
ese día no supimos si ellos cambiaron algo. Causas probables: su sitio caído, Cloudflare
bloqueando la revisión automática o un rediseño de su página (ajustar
`app/Modules/Calculators/Services/FormulaReader.php`).

## 7. Seguridad

- No hay rutas ni endpoints nuevos: el cálculo corre en el navegador con datos que escribe la
  persona y no se guarda nada.
- Las pantallas no usan `v-html`; todo texto se escapa. Los montos y días tienen límites y se
  validan antes de calcular.
- La revisión diaria solo descarga dos URL fijas de configuración: https, sin seguir
  redirecciones, 10 s como máximo y un tope de 1 MB. Su JavaScript nunca se ejecuta: solo se leen
  números y tablas con expresiones regulares.
