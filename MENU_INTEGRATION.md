# Integración del Menú Lateral de ServerSplitter en Pterodactyl

## Descripción

ServerSplitter se integra en el menú lateral (sidebar) del cliente de Pterodactyl mediante inyección dinámica de JavaScript. El menú se registra automáticamente cuando el usuario visualiza un servidor.

## Cómo Funciona

### 1. Inyección de JavaScript

El script `serversplitter-inject.js` se inyecta en la vista del servidor y ejecuta automáticamente cuando el DOM está listo.

**Ubicación:** `src/resources/assets/assets/js/serversplitter-inject.js`

### 2. Proceso de Registro del Menú

1. **Detección del Sidebar:** El script busca el contenedor del sidebar (`.server-navigation`)
2. **Creación del Item:** Crea un elemento `<li>` con un enlace `<a>` que contiene:
   - Icono: `fa-object-group`
   - Etiqueta: "Divisiones"
   - Ruta: `{{ route("serversplitter.show") }}`
3. **Inserción:** Inserta el item después del último elemento de navegación existente
4. **Manejador de Eventos:** Registra un listener para cargar contenido dinámicamente sin recargar

### 3. Carga de Contenido

Cuando el usuario hace clic en "Divisiones":

1. Se previene el comportamiento predeterminado del enlace
2. Se realiza una solicitud AJAX GET al endpoint `serversplitter.show`
3. El servidor devuelve el HTML renderizado
4. Se inyecta el contenido en el área designada (`#content`)
5. El enlace se marca como activo

### 4. Estructura de Carpetas
