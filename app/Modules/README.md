# 🧩 App\Modules — Módulos de Negocio

Este directorio está reservado exclusivamente para los módulos de negocio de cada nuevo proyecto o cliente. 
Mantiene la aplicación desacoplada, modular y fácil de mantener o migrar.

---

## 🏗️ Convención de Estructura por Módulo

Cada módulo debe crearse dentro de su propia carpeta en `PascalCase` (ejemplo: `app/Modules/Invoicing/`, `app/Modules/CRM/`, `app/Modules/Products/`):

```
app/Modules/CustomerManagement/
├── Controllers/
│   └── CustomerController.php
├── Models/
│   └── Customer.php
├── Requests/
│   ├── StoreCustomerRequest.php
│   └── UpdateCustomerRequest.php
├── Services/
│   └── CustomerService.php
├── Routes/
│   └── web.php
└── Views/ (opcional si usa Blade modular)
```

---

## 🚀 Cómo Registrar las Rutas de un Módulo en Laravel 12

En Laravel 12, las rutas adicionales se registran limpiamente en `bootstrap/app.php` mediante el método `then()`:

```php
// bootstrap/app.php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
    then: function () {
        // Rutas del módulo CustomerManagement
        Route::middleware(['web', 'auth'])
            ->prefix('customers')
            ->name('customers.')
            ->group(base_path('app/Modules/CustomerManagement/Routes/web.php'));
    },
)
```

---

## 🔒 Buenas Prácticas para Módulos
1. **Controladores Delgados:** Delegar la lógica pesada a `Services/` o `Actions/`.
2. **Validación Obligatoria:** Todo endpoint POST/PUT/PATCH debe usar un `FormRequest` ubicado en `Requests/`.
3. **Autorización Granular:** Proteger rutas con middlewares de Spatie (`role:Admin`, `permission:manage-customers`) o Policies.
4. **Almacenamiento Privado:** Documentos o comprobantes generados deben guardarse mediante `App\Core\Storage\PrivateVault`.
5. **Auditoría:** Los modelos críticos deben incorporar el trait `LogsActivity` configurado para registrar cambios.
