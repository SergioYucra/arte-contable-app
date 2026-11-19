# Protección de los libros contra la descarga masiva

Rama `propuesta/seguridad-libros`. Módulo `app/Modules/LibraryProtection`.

## 1. El problema

El lector entrega cada página del libro como una imagen WebP desde
`GET /biblioteca/ediciones/{edición}/paginas/{página}`. Antes de este cambio:

| Qué pasaba | Consecuencia |
|---|---|
| El único freno era `throttle:120,1` | Una cuenta con membresía bajaba un libro de 300 páginas en unos 3 minutos con un script en la consola del navegador |
| Todos recibían los mismos bytes | Una página filtrada en WhatsApp no se podía rastrear |
| No había registro de lectura | El copiado no se notaba ni después |
| Una cuenta podía leer en muchos dispositivos a la vez | Una membresía pagada servía a un grupo entero |
| `hasActiveSubscription()` sin la edición en el permiso de página | Una suscripción a un solo libro abría todos (fallo latente) |
| El contador de `throttle:120,1` era compartido | Quien leía varias páginas recibía "demasiados intentos" en otras rutas |

Lo que ya estaba bien y se conserva:
- El PDF nunca llega al servidor (se convierte a WebP en el navegador del administrador).
- La bóveda privada está fuera de `public/`.
- La muestra gratuita se controla en el servidor.

**Objetivo realista.** Lo que se ve en pantalla siempre se puede capturar (el llamado
"agujero analógico"). Ninguna plataforma lo evita del todo. Lo que sí se logra, y es lo que
hacen VitalSource, ProQuest o Perlego, es que robar un libro sea **lento, quede registrado y
se sepa quién fue**.

## 2. OWASP

No existe una edición 2024 del OWASP Top 10: las vigentes son la 2021 y la **2025**, que es la
que usa la revisión de seguridad de `fix/revision-3`. Para este riesgo también aplican dos
guías de OWASP más específicas:

- **Automated Threats to Web Applications:** OAT-011 *Scraping* (copiar contenido para usarlo
  en otro lado, también con cuentas legítimas) y OAT-020 *Account Aggregation*.
- **API Security Top 10 2023:** API4 *Unrestricted Resource Consumption* y API6
  *Unrestricted Access to Sensitive Business Flows* (leer una página es el flujo sensible).

| OWASP Top 10:2025 | Cómo se cubre |
|---|---|
| A01 Control de acceso | Suscripción por edición en el permiso de página (`User::canReadEditionPage`); una sola regla para controlador y modelo; panel solo con `permission:manage-users` |
| A02 Configuración | `php artisan libros:diagnostico` antes de desplegar |
| A03 Cadena de suministro | Sin paquetes nuevos (GD puro); la fuente se fijó a un commit de google/fonts y se verifica su SHA-256 |
| A06 Diseño inseguro | Límite por minuto, tope diario, un dispositivo a la vez y pausa automática (OAT-011, API4, API6) |
| A07 Autenticación | Un dispositivo a la vez y aviso de cuenta compartida. Turnstile, 2FA y bloqueo de inicio de sesión vienen en `fix/revision-3` |
| A09 Registro y alertas | `book_page_views` (quién vio qué), bitácora de seguridad y correo al administrador |
| A10 Condiciones excepcionales | Si la marca de agua no se puede poner, el lector se detiene (falla cerrado) y avisa |

## 3. Qué hacen otras plataformas

| Medida | Quién la usa | Fuente |
|---|---|---|
| Tope de páginas por usuario y periodo | ProQuest Ebook Central (40 % por 24 h), EBSCO, VitalSource, Perlego (10 %) | [UCSD](https://ucsd.libguides.com/ebooks/platforms), [VCU](https://guides.library.vcu.edu/ebooks/ebsco), [Perlego](https://publishers.perlego.com/en/support/data-security-content-protection) |
| Detección de velocidad no humana y bloqueo automático | Perlego, proxies de bibliotecas (NCSU, Navarra) | [Perlego](https://publishers.perlego.com/en/support/data-security-content-protection), [NCSU](https://www.lib.ncsu.edu/node/50805) |
| Marca de agua con los datos del lector | BooXtream, BookFunnel | [BookFunnel](https://blog.bookfunnel.com/2022/announcing-visible-watermarks), [TorrentFreak](https://torrentfreak.com/researchers-crack-social-drm-ebook-watermarks-160625/) |
| Límite de dispositivos | Netflix, Google Play Books (libros de texto: 1) | [Netflix](https://help.netflix.com/node/124925), [Google Play Books](https://support.google.com/books/partner/answer/3309439?hl=en) |
| Páginas trampa | AWS WAF (honeypot) | [AWS](https://docs.aws.amazon.com/solutions/latest/security-automations-for-aws-waf/embed-the-honeypot-link-in-your-web-application-optional.html) |
| Rechazar la imagen fuera del lector (Fetch Metadata) | Recomendación de web.dev | [web.dev](https://web.dev/articles/fetch-metadata) |
| Límites por usuario en Laravel | Documentación oficial | [Laravel](https://laravel.com/docs/12.x/routing#rate-limiting) |

**Descartado, con motivo:**
- *Imágenes troceadas o cifradas* (como ComicWalker o SpeedBinb): hay programas públicos que
  las rearman y cuestan mucho procesamiento.
- *Detectar DevTools o capturas de pantalla:* se evade en minutos.
- *Marca forense invisible paga* (IMATAG, Digimarc): costo mensual; si aparece una filtración
  real que la marca visible no resuelva, se puede reconsiderar.

## 4. Qué se implementó

### En el servidor (`app/Modules/LibraryProtection`)

`ReadingGuard` revisa cada pedido de página en este orden (la primera que falla responde):

1. **Origen:** con `Sec-Fetch-Site` distinto de `same-origin`, o `Sec-Fetch-Dest` `iframe`,
   `embed` u `object`, responde 403. Si se abre la dirección en una pestaña
   (`Sec-Fetch-Dest: document`), redirige al lector en esa página. Sin esas cabeceras
   (navegadores antiguos), se acepta.
2. **Pausa vigente:** responde 423 `lectura_pausada`, con la hora en que termina.
3. **Edición publicada y página dentro del libro:** si no, 404. Pedir una página fuera del
   libro suma una marca trampa; una edición recién despublicada no suma (quien la tenía
   abierta reintenta con todo derecho).
4. **Un dispositivo a la vez** (`ReaderDevices`): cada navegador recibe una cookie cifrada y
   solo-HTTP, `lector_dispositivo`, con un valor aleatorio. La cuenta guarda el hash del
   dispositivo con el turno.
   - Si otro dispositivo leyó hace menos de 10 minutos, responde 409 `otro_dispositivo`.
   - "Leer aquí" (`POST /biblioteca/lector/aqui`) toma el turno.
   - Se usa una cookie de dispositivo y no el ID de sesión, para que cerrar sesión y volver
     a entrar en el mismo navegador no cuente como otro dispositivo.
5. **Permiso:** muestra gratuita o membresía de esa edición. Si no, 403 `membresia` y suma una
   marca trampa.
6. **Tope diario:** 300 páginas distintas por día (hora de Bolivia). Volver a una página ya
   vista no cuenta. Al llegar, responde 429 `limite_diario`.
7. **Registro** en `book_page_views`: persona, edición, página, día, visitas e IP.
8. **Velocidad no humana:** 80 páginas nuevas en 5 minutos, o 150 en 30, pausan la lectura
   6 horas y mandan un correo al administrador. Lo leído antes de que terminara la última
   pausa no se vuelve a contar: al reactivar, la cuenta empieza de cero.

Además:
- **Pedidos trampa:** 5 en 30 minutos pausan la lectura (motivo "pidió páginas que el lector
  nunca pide").
- **Cuenta compartida:** 6 cambios de dispositivo o 5 conexiones (IP) distintas en 24 horas
  generan un aviso, sin pausa. Las IP de cada cuenta se guardan en la caché durante la
  ventana.
- **Límite por minuto:** `RateLimiter::for('book-pages')`, 30 por minuto por persona (120 para
  el personal), con contador propio.
- **Personal (SuperAdmin/Admin):** sin tope, pausa ni límite de dispositivo. Lo que lee queda
  registrado y lleva marca de agua.

`PageWatermarker` dibuja la marca con GD en cada pedido (unos 110 ms por página de
1200×1697 px):
- en diagonal, en mosaico y tenue (≈15 %): nombre, correo a medias, fecha y código;
- al pie, más legible: "Copia personal de … · Código … · fecha · Prohibido compartir";
- el código en las dos esquinas superiores, para que recortar una no lo borre.

El **código del lector** (`reader_codes`, por ejemplo `K7QM-3XPA`) es aleatorio y no usa
caracteres que se confunden en una foto (0/O, 1/I/L). El administrador lo busca en el panel.

**Falla cerrado:** si GD, WebP, FreeType o la fuente faltan, el lector responde 503
`lector_detenido` y avisa al administrador. Nunca entrega una página sin marca.

### En el lector (`StudentLibraryCatalog.vue` + `books/readerPageLoader.js`)

- Las páginas se piden con `fetch` y se muestran como `blob:`. Se guardan en memoria las
  últimas 6 y se liberan al cerrar el lector.
- Espera de 250 ms antes de pedir: al mantener la flecha solo se pide la página donde la
  persona se detiene, y la barra de páginas pide recién al soltarla. Las páginas por las que
  solo se pasó no cuentan para el límite de hoy.
- Un aviso propio para cada caso, con la fórmula *qué pasó → por qué → qué hacer*: otro
  dispositivo ("Leer aquí"), límite de hoy, lectura pausada, muy rápido, sin conexión,
  sesión vencida, lector detenido.
- "Te quedan N páginas nuevas por hoy" cuando quedan menos de 30.
- "Copia personal de {nombre} · No se puede compartir" bajo la página.
- Fricción ligera: sin clic derecho ni arrastre, Ctrl+P y Ctrl+S bloqueados, y al imprimir
  sale un aviso en vez de la página.
- El avance se guarda cuando la página llega, no antes.
- Corrección de paso: el `BaseToast` del lector recibía props que no existen y nunca se
  mostraba.

### Panel del administrador (`/admin/libros/proteccion`)

Enlace "Protección de libros" en la cabecera de *Libros y ediciones*. La barra lateral no se
tocó porque la modifica `fix/revision-3`.

- **Lecturas pausadas:** motivo en palabras llanas, *Reactivar lectura* y *Quitar acceso*
  (reutiliza `admin.subscriptions.members.revoke`, que deja constancia en el historial de
  membresía).
- **¿Quién filtró esta página?:** se escribe el código de la captura y aparece la persona y lo
  que leyó en los últimos 30 días (libros, rangos de páginas, IP).
- **Posibles cuentas compartidas** y **quién leyó más hoy**.

Cada acción del administrador queda en `activity('security')`.

### Correos al administrador

Llevan el nombre que escribió el estudiante. `LibraryProtectionServiceProvider` activa
`Markdown::withSecuredEncoding()`, la opción segura de Laravel. Así un nombre como
`[Panel](https://sitio-falso)` no se convierte en un enlace dentro de un correo oficial; lo
encontró la revisión de seguridad y una prueba lo comprueba. La opción vale para todos los
correos Markdown de la aplicación; hoy no hay otros que dependan de que se interprete Markdown
en lo que se imprime con `{{ }}`.

### Configuración (`config/library.php`, clave `protection`)

| Clave | Por defecto | Variable de entorno |
|---|---|---|
| `pages_per_minute` | 30 | `LIBRARY_PAGES_PER_MINUTE` |
| `daily_page_limit` | 300 | `LIBRARY_DAILY_PAGE_LIMIT` |
| `velocity_rules` | 80 en 5 min, 150 en 30 min | — |
| `trap_strikes` | 5 en 30 min | — |
| `pause_hours` | 6 | `LIBRARY_PAUSE_HOURS` |
| `device_idle_minutes` | 10 | — |
| `retention_days` | 180 | `LIBRARY_PAGE_VIEWS_RETENTION_DAYS` |

### Comandos

- `php artisan libros:diagnostico`: revisa GD con WebP y FreeType, la fuente (SHA-256), una
  marca de prueba y el correo.
- `php artisan libros:limpiar-registro`: borra el registro de lectura de más de 180 días.
  Corre todos los días a las 03:30 (hora de Bolivia).

Todo se registra desde `LibraryProtectionServiceProvider`, en `bootstrap/providers.php`. Así no
se tocan `bootstrap/app.php`, `routes/console.php`, `AppServiceProvider`, `config/session.php`
ni la barra lateral, que modifica `fix/revision-3`.

## 5. Lo que queda (riesgo residual)

- **Capturas de pantalla:** siguen siendo posibles, pero cada una lleva nombre, correo a
  medias, fecha y código.
- **Una persona paciente** puede copiar 300 páginas por día de forma "humana". El registro deja
  rastro y la marca la identifica.
- **Muchas cuentas falsas** solo obtienen las páginas de muestra (12 por defecto).
  Recomendación: no subir la muestra por encima de unas 20 páginas.
- **Pedidos simultáneos:** el tope diario y el cambio de dispositivo se revisan sin bloqueo
  de base de datos. Con muchos pedidos al mismo tiempo se pueden pasar unas pocas páginas del
  tope; el límite por minuto (30) acota cuántas.
- **IP reales detrás de Cloudflare:** sin `TRUSTED_PROXIES`, todas las lecturas parecen venir
  de la misma IP y el aviso de "conexiones distintas" no sirve.

## 6. Lista para el despliegue (Hostinger)

1. Ejecutar `php artisan libros:diagnostico`. Si GD no tiene WebP o FreeType, activarlos en el
   panel de PHP de Hostinger antes de publicar; si no, el lector se detiene.
2. Cron cada minuto: `php artisan schedule:run` (limpieza diaria del registro).
3. `MAIL_MAILER` real para que lleguen los avisos (el diagnóstico lo revisa).
4. `TRUSTED_PROXIES` si hay Cloudflare o un proxy delante.
5. Los respaldos de `fix/revision-3` incluyen `storage/app/private_vault` (todas las páginas
   de los libros): guardarlos fuera del servidor y con contraseña (`BACKUP_ARCHIVE_PASSWORD`).
6. Recomendación para Max: agregar a los términos de uso que la cuenta es personal, que está
   prohibido el acceso automatizado y compartir páginas, y que eso permite suspender la cuenta
   sin reembolso. Es el respaldo legal de las pausas y del retiro de acceso.

## 7. Cuando se integre `fix/revision-3`

- Agregar el middleware `two-factor` a las rutas del panel (`app/Modules/LibraryProtection/Routes/web.php`).
- Pasar `ReadingAlerts` a `App\Support\Security\SecurityAlert`. Hoy usa su propia notificación
  porque la de esa rama enlaza a `admin.audit-logs.index`, que no existe en `main`.
- `AccessDeniedLogger` registrará también los 403 del lector (uno cada 10 minutos por persona,
  por su deduplicación).

## 8. Pruebas

- **PHPUnit, `tests/Feature/LibraryProtection/`:** 43 pruebas (tope, velocidad, trampas,
  dispositivos, marca de agua, falla cerrado, acceso por edición, panel, limpieza y
  diagnóstico).
  - Requieren GD con WebP y FreeType: imagen local `arte-php83-gd:local`.
  - `LibraryControllerTest` y `MembershipMembersTest` ahora envían una cookie de dispositivo.
  - `MembershipRulesTest` cuenta sus pasos de `migrate:rollback` en vez de fijarlos en 2.
- **JS (`npm run test:js`):** `tests/js/books/readerPageLoader.test.js`.
- **Navegador (Playwright):** lectura con marca, "Te quedan…", tope diario, dos dispositivos
  (computadora y celular), un script de copiado pausado a los 75 segundos, panel, búsqueda del
  código, reactivar y quitar acceso.
