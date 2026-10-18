# 🛡️ App\Core — Núcleo Inmutable del Master Boilerplate

Este directorio contiene las clases base, contratos, utilidades globales, servicios de seguridad y almacenamiento que constituyen los cimientos de la aplicación.

## 📌 Reglas de Oro
1. **Intocable entre proyectos:** El código aquí ubicado es universal. No debe contener lógica de negocio específica de un cliente particular.
2. **Alta seguridad por defecto:** Todo componente de Core debe aplicar principios de mínimo privilegio, sanitización contra traversal e inyecciones, y validación estricta.

## 📂 Estructura
- `Storage/`
  - `PrivateVault.php`: Servicio seguro para almacenar, transmitir y eliminar archivos sensibles fuera de la raíz pública (`storage/app/private_vault`), con cabeceras `nosniff`, streaming seguro y nombres basados en UUID.
- `Traits/`: Traits reutilizables en modelos y controladores de cualquier módulo.
- `Helpers/`: Funciones de utilidad global puras.
