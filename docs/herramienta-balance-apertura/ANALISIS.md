# Herramienta: Crear balance de apertura

Análisis de la página que tomamos de modelo y cómo la copiamos. Revisado el 18/11/2026.

- Su página: https://boliviaimpuestos.com/maletin/balance-apertura.php
- La nuestra: **Herramientas → Crear Balance de apertura** (`/herramientas`).

## 1. Cómo funciona su página

No tiene API: todo se calcula en el JavaScript de la página, igual que el RC-IVA y el bono.
Los únicos `fetch` son para comentarios y la suscripción al boletín. El resultado es un documento
que se imprime con `window.print()`.

**Datos:** tipo de empresa (Unipersonal o S.R.L.), fecha, nombre o razón social, NIT, ciudad
(9 capitales de departamento), capital total, socios con su porcentaje (solo S.R.L.) y activos
fijos en 4 grupos: Muebles y Enseres, Equipo de Computación, Herramientas y Otros Activos. Cada
activo lleva descripción, cantidad y precio unitario.

**Cálculo:**

| Qué | Cómo |
|---|---|
| Valor de un activo | cantidad × precio unitario. Solo cuenta si tiene descripción, cantidad y precio mayores que cero |
| Activo fijo | suma de los grupos |
| Caja / Bancos | capital − activo fijo |
| Total activo | Caja / Bancos + activo fijo (= capital) |
| Cuadra | total activo = capital y Caja / Bancos no negativa |
| Aporte de un socio | capital × % ÷ 100; entra al balance si tiene nombre y % |
| Aviso de socios | los porcentajes de los que entran deben sumar 100% |

**Documento:** ACTIVO → Activo Disponible → Caja / Bancos; Activo Fijo → cada grupo con valor;
PASIVO + PATRIMONIO → Patrimonio → "Aporte {socio} ({%})" por socio, o "Capital" si no hay socios.

## 2. Lo que copiamos tal cual y lo que mejoramos

**Igual:** el cálculo, las filas del documento y sus textos. Se prueba contra 9 casos capturados
de su página real (`tests/js/fixtures/balance-apertura-casos.json`): mismas cifras, mismas filas.

**Mejoras (en los 9 casos capturados las cifras son idénticas a las suyas):**

- **No se imprime un balance que no cuadra.** Su página deja imprimir con la Caja en negativo
  (cuando los activos fijos superan el capital) y con aportes que no suman el capital (porcentajes
  que suman 90%). La nuestra muestra "Antes de imprimir" con lo que falta y bloquea el botón.
- **Filas a medio llenar.** Su página las ignora en silencio; la nuestra las marca en rojo y dice qué
  falta ("falta la descripción", "el precio va como 1.500 o 1.500,50").
- **Centavos.** La caja y los aportes se redondean al centavo. Su página no lo hace y, con decimales,
  puede marcar "no cuadra" por -0,0000000000001 (3 × 1,10 contra un capital de 3,30) o imprimir aportes
  que no suman el capital por un centavo (50% y 50% de 100,01). Si los porcentajes suman 100%, el último
  socio absorbe ese centavo.
- **Montos escritos a la boliviana.** "300.000" es trescientos mil (su página lo lee como 300).
- **Cada fila muestra su cuenta:** "2 × Bs 1.500,00 = Bs 3.000,00".
- **Documento:** totales con doble subrayado contable y líneas de firma (propietario o representante
  legal, y contador). Se imprime solo el documento, en tamaño carta; el navegador propone como nombre
  del PDF "Balance de apertura - {empresa}".

## 3. Dónde está cada cosa

| Qué | Dónde |
|---|---|
| Cálculo (sin Vue) | `resources/js/components/tools/balance-apertura/balanceApertura.js` |
| Opciones (tipos, ciudades, grupos, límites) | `tools/balance-apertura/catalogos.js` |
| Pantalla | `tools/balance-apertura/BalanceAperturaTool.vue` (registrada en `StudentToolsCatalog.vue`) |
| Documento (vista previa e impresión) | `tools/balance-apertura/BalanceDocument.vue` |
| Pruebas contra su página | `tests/js/tools/balanceApertura.test.js` (`npm run test:js`) |
| Script que capturó los casos | `docs/herramienta-balance-apertura/capturar-casos.cjs` |

## 4. Revisión diaria

No se suma a `calculators:check`: no tiene tasas ni tablas que cambien por norma (Activo = Patrimonio
es la regla contable de siempre). Si ellos agregan grupos de activo o pasivos, se ve en su página y
se ajusta `catalogos.js`.

## 5. Seguridad

No hay rutas ni endpoints nuevos y no se guarda nada: el balance se arma y se imprime en el navegador.
Todo texto se muestra escapado (sin `v-html`). El NIT acepta solo dígitos, y los textos, cantidades y
montos tienen límites.
