{{--
    Script inyectado en el cliente Pterodactyl que maneja la navegación SPA del sidebar
    de servidores. Registra el item "Divisiones" en el menú y carga el contenido del plugin
    sin recargar la página.

    Se inyecta automáticamente en la vista del servidor del cliente de Pterodactyl.
--}}

<script>
(function() {
    'use strict';

    // Configuración
    const PLUGIN_ROUTE = '{{ route("serversplitter.show") }}';
    const PLUGIN_NAME = 'serversplitter';
    const MENU_LABEL = 'Divisiones';
    const MENU_ICON = 'fa-object-group'; // o usar otro icono: fa-split, fa-layer-group, etc.

    /**
     * Inyecta el item del menú "Divisiones" en la navegación del servidor.
     * Se ejecuta cuando el DOM está listo.
     */
    function injectMenuItemIntoSidebar() {
        // Esperar a que Pterodactyl renderice el sidebar
        const sidebar = document.querySelector('[data-v-app] .server-navigation') ||
                        document.querySelector('.server-navigation') ||
                        document.querySelector('nav[role="navigation"]');

        if (!sidebar) {
            // Reintentar en 100ms si no encuentra el sidebar
            setTimeout(injectMenuItemIntoSidebar, 100);
            return;
        }

        // Verificar si ya existe el item de ServerSplitter
        const existingItem = document.querySelector(`[data-plugin="${PLUGIN_NAME}"]`);
        if (existingItem) {
            return; // Ya está agregado, evitar duplicados
        }

        // Crear el contenedor del item del menú (elemento <li>)
        const menuItemLi = document.createElement('li');
        menuItemLi.className = 'nav-item';
        menuItemLi.setAttribute('data-plugin', PLUGIN_NAME);

        // Crear el enlace del menú (elemento <a>)
        const menuLink = document.createElement('a');
        menuLink.href = PLUGIN_ROUTE;
        menuLink.className = 'nav-link';
        menuLink.setAttribute('data-toggle', 'tab');
        menuLink.setAttribute('data-bs-toggle', 'tab'); // Bootstrap 5
        menuLink.setAttribute('role', 'tab');
        menuLink.setAttribute('aria-selected', 'false');

        // Crear el icono
        const icon = document.createElement('i');
        icon.className = `fa-fw fas ${MENU_ICON}`;
        icon.setAttribute('aria-hidden', 'true');

        // Crear el texto del menú
        const textSpan = document.createElement('span');
        textSpan.className = 'nav-link-text';
        textSpan.textContent = MENU_LABEL;

        // Construir la jerarquía: enlace -> icono + texto
        menuLink.appendChild(icon);
        menuLink.appendChild(document.createTextNode(' '));
        menuLink.appendChild(textSpan);

        // Construir el item: li -> a
        menuItemLi.appendChild(menuLink);

        // Insertar después del último item de navegación existente
        // Pterodactyl típicamente tiene: Console, Settings, etc.
        const lastNavItem = sidebar.querySelector('li.nav-item:last-child');
        if (lastNavItem && lastNavItem.nextSibling) {
            lastNavItem.parentNode.insertBefore(menuItemLi, lastNavItem.nextSibling);
        } else if (lastNavItem) {
            lastNavItem.parentNode.appendChild(menuItemLi);
        } else {
            // Si no hay items previos, agregar directamente al sidebar
            sidebar.appendChild(menuItemLi);
        }

        // Registrar el manejador de clics para cargar el contenido
        menuLink.addEventListener('click', handleMenuClick);

        // Agregar estilos CSS si es necesario (para asegurar alineación correcta)
        ensureMenuStyles();
    }

    /**
     * Maneja el clic en el menú de Divisiones.
     * Carga el contenido dinámicamente sin recargar la página.
     */
    function handleMenuClick(e) {
        e.preventDefault();

        // Obtener el elemento contenedor del contenido
        const contentArea = document.querySelector('#content') ||
                            document.querySelector('[role="tabpanel"]') ||
                            document.querySelector('.tab-content');

        if (!contentArea) {
            console.error('ServerSplitter: no se encontró el contenedor del contenido');
            return;
        }

        // Mostrar indicador de carga si existe
        const loader = document.querySelector('#loader') ||
                       document.querySelector('.spinner-border') ||
                       document.querySelector('.loading-indicator');
        if (loader) {
            loader.style.display = 'block';
        }

        // Realizar la solicitud al servidor
        fetch(PLUGIN_ROUTE, {
            method: 'GET',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin'
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(html => {
            // Insertar el contenido HTML en el área designada
            contentArea.innerHTML = html;

            // Marcar el enlace como activo
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
                link.setAttribute('aria-selected', 'false');
            });
            e.currentTarget.classList.add('active');
            e.currentTarget.setAttribute('aria-selected', 'true');

            // Reinicializar componentes que pudieran estar en el nuevo HTML
            // (ej: validadores, tooltips, datepickers, etc.)
            reinitializeComponents();
        })
        .catch(error => {
            console.error('ServerSplitter: error al cargar el contenido:', error);
            contentArea.innerHTML = `
                <div class="alert alert-danger" role="alert">
                    <strong>Error:</strong> No se pudo cargar el contenido de Divisiones.
                    Por favor, recarga la página e intenta de nuevo.
                </div>
            `;
        })
        .finally(() => {
            // Ocultar indicador de carga
            if (loader) {
                loader.style.display = 'none';
            }
        });
    }

    /**
     * Asegura que los estilos CSS del menú sean correctos.
     */
    function ensureMenuStyles() {
        // Verificar si ya existen los estilos
        if (document.getElementById('serversplitter-menu-styles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'serversplitter-menu-styles';
        style.textContent = `
            li[data-plugin="serversplitter"] {
                margin: 0.25rem 0;
            }

            li[data-plugin="serversplitter"] .nav-link {
                display: flex;
                align-items: center;
                padding: 0.75rem 1rem;
                border-radius: 0.375rem;
                transition: all 0.2s ease;
            }

            li[data-plugin="serversplitter"] .nav-link:hover {
                background-color: rgba(0, 0, 0, 0.05);
            }

            li[data-plugin="serversplitter"] .nav-link.active {
                background-color: rgba(0, 0, 0, 0.1);
                font-weight: 500;
            }

            li[data-plugin="serversplitter"] .fa-fw {
                margin-right: 0.75rem;
                min-width: 1.25rem;
                text-align: center;
            }

            li[data-plugin="serversplitter"] .nav-link-text {
                flex: 1;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            /* Soporte para dark mode */
            @media (prefers-color-scheme: dark) {
                li[data-plugin="serversplitter"] .nav-link:hover {
                    background-color: rgba(255, 255, 255, 0.1);
                }

                li[data-plugin="serversplitter"] .nav-link.active {
                    background-color: rgba(255, 255, 255, 0.15);
                }
            }
        `;
        document.head.appendChild(style);
    }

    /**
     * Reinicializa componentes JavaScript que puedan estar en el contenido cargado.
     * (Por ejemplo: validadores de formularios, tooltips, etc.)
     */
    function reinitializeComponents() {
        // Si tu proyecto usa Bootstrap, reinicializar tooltips
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                new bootstrap.Tooltip(el);
            });
        }

        // Si uses jQuery o alguna otra librería, agregá la reinicialización aquí
        // Por ejemplo: $(document).trigger('reinit'); o similar
    }

    /**
     * Punto de entrada: esperar a que el DOM esté listo e inyectar el menú.
     */
    function init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', injectMenuItemIntoSidebar);
        } else {
            injectMenuItemIntoSidebar();
        }
    }

    // Ejecutar inicialización
    init();
})();
</script>