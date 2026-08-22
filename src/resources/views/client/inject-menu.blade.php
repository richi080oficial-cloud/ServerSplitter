<script>
(function() {
    'use strict';

    const PLUGIN_ROUTE = '{{ route("serversplitter.show") }}';
    const PLUGIN_NAME = 'serversplitter';
    const MENU_LABEL = 'Divisiones';
    const MENU_ICON = 'fa-object-group';

    function injectMenuItemIntoSidebar() {
        const sidebar = document.querySelector('[data-v-app] .server-navigation') ||
                        document.querySelector('.server-navigation') ||
                        document.querySelector('nav[role="navigation"]');

        if (!sidebar) {
            setTimeout(injectMenuItemIntoSidebar, 100);
            return;
        }

        const existingItem = document.querySelector(`[data-plugin="${PLUGIN_NAME}"]`);
        if (existingItem) {
            return;
        }

        const menuItemLi = document.createElement('li');
        menuItemLi.className = 'nav-item';
        menuItemLi.setAttribute('data-plugin', PLUGIN_NAME);

        const menuLink = document.createElement('a');
        menuLink.href = PLUGIN_ROUTE;
        menuLink.className = 'nav-link';
        menuLink.setAttribute('data-toggle', 'tab');
        menuLink.setAttribute('data-bs-toggle', 'tab');
        menuLink.setAttribute('role', 'tab');
        menuLink.setAttribute('aria-selected', 'false');

        const icon = document.createElement('i');
        icon.className = `fa-fw fas ${MENU_ICON}`;
        icon.setAttribute('aria-hidden', 'true');

        const textSpan = document.createElement('span');
        textSpan.className = 'nav-link-text';
        textSpan.textContent = MENU_LABEL;

        menuLink.appendChild(icon);
        menuLink.appendChild(document.createTextNode(' '));
        menuLink.appendChild(textSpan);

        menuItemLi.appendChild(menuLink);

        const lastNavItem = sidebar.querySelector('li.nav-item:last-child');
        if (lastNavItem && lastNavItem.parentNode) {
            lastNavItem.parentNode.insertBefore(menuItemLi, lastNavItem.nextSibling);
        } else {
            sidebar.appendChild(menuItemLi);
        }

        menuLink.addEventListener('click', handleMenuClick);
        ensureMenuStyles();
    }

    function handleMenuClick(e) {
        e.preventDefault();

        const contentArea = document.querySelector('#content') ||
                            document.querySelector('[role="tabpanel"]') ||
                            document.querySelector('.tab-content');

        if (!contentArea) {
            console.error('ServerSplitter: contenedor no encontrado');
            return;
        }

        const loader = document.querySelector('#loader') ||
                       document.querySelector('.spinner-border') ||
                       document.querySelector('.loading-indicator');

        if (loader) loader.style.display = 'block';

        fetch(PLUGIN_ROUTE, {
            method: 'GET',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin'
        })
        .then(response => response.ok ? response.text() : Promise.reject(response))
        .then(html => {
            contentArea.innerHTML = html;

            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
                link.setAttribute('aria-selected', 'false');
            });
            e.currentTarget.classList.add('active');
            e.currentTarget.setAttribute('aria-selected', 'true');
        })
        .catch(error => {
            console.error('ServerSplitter error:', error);
            contentArea.innerHTML = `<div class="alert alert-danger">Error al cargar el contenido.</div>`;
        })
        .finally(() => {
            if (loader) loader.style.display = 'none';
        });
    }

    function ensureMenuStyles() {
        if (document.getElementById('serversplitter-menu-styles')) return;

        const style = document.createElement('style');
        style.id = 'serversplitter-menu-styles';
        style.textContent = `
            li[data-plugin="serversplitter"] .nav-link {
                display: flex;
                align-items: center;
                padding: 0.75rem 1rem;
            }
            li[data-plugin="serversplitter"] .nav-link.active {
                background-color: rgba(0, 0, 0, 0.1);
                font-weight: 500;
            }
            li[data-plugin="serversplitter"] .fa-fw {
                margin-right: 0.75rem;
            }
        `;
        document.head.appendChild(style);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectMenuItemIntoSidebar);
    } else {
        injectMenuItemIntoSidebar();
    }
})();
</script>