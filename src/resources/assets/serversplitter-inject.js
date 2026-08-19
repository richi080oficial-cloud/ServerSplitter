/**
 * ServerSplitter - integracion con el panel de cliente de Pterodactyl.
 *
 * El panel de cliente es una SPA de React con clases generadas por
 * styled-components, asi que no hay forma estable de "montar" un componente
 * desde fuera. Lo que se hace aqui es localizar el grupo de addons del sidebar
 * del servidor (el contenedor con data-theme-layout-group="server:addons", donde
 * viven Plugins, Server Config, Subdomains...) y anadir un enlace propio
 * clonando la estructura y las clases de un enlace vecino: <a> > <span icono> +
 * <span etiqueta>. Asi el item hereda el estilo exacto del tema activo.
 *
 * Si ese grupo no existe (Pterodactyl sin tema, o temas antiguos) se cae hacia
 * la barra de navegacion clasica del servidor.
 *
 * Es tolerante a los re-render de React (MutationObserver) y a la navegacion
 * por historial (pushState / replaceState / popstate). No depende de ninguna
 * libreria y se ejecuta una sola vez por documento.
 */
(function () {
    'use strict';

    if (window.__serverSplitterInjected) {
        return;
    }
    window.__serverSplitterInjected = true;

    var ATTR = 'data-serversplitter-link';
    var LABEL = 'Divisiones';
    var EDITOR_ID = 'server:splitter';
    var SERVER_PATH = /^\/server\/([A-Za-z0-9]+(?:-[A-Za-z0-9]+)*)/;

    /** Grupo del sidebar donde el tema agrupa las extensiones del servidor. */
    var GROUP_SELECTOR = '[data-theme-layout-group="server:addons"]';

    /** Enlaces del sidebar del tema, usados como plantilla de estructura. */
    var THEME_ITEM_SELECTOR = 'a[data-theme-editor-id^="server:"]';

    var pending = false;

    /**
     * Cache por servidor del resultado de /server/<id>/serversplitter/availability:
     * true (mostrar), false (ocultar) o 'pending' mientras se resuelve. Solo se
     * consulta una vez por servidor y por carga de pagina.
     */
    var availability = {};
    var links = {};

    /** Identificador corto del servidor abierto, o null si no estamos en uno. */
    function currentIdentifier() {
        var match = SERVER_PATH.exec(window.location.pathname);

        return match ? match[1] : null;
    }

    /** Icono propio (bloques divididos). SVG estatico, sin dependencias. */
    function iconMarkup(svgClass) {
        return '<svg aria-hidden="true" focusable="false" role="img"'
            + ' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"'
            + ' draggable="false"'
            + (svgClass ? ' class="' + svgClass + '"' : '')
            + '>'
            + '<g fill="none" stroke="currentColor" stroke-width="42"'
            + ' stroke-linejoin="round">'
            + '<rect x="53" y="53" width="158" height="406" rx="26"></rect>'
            + '<rect x="301" y="53" width="158" height="158" rx="26"></rect>'
            + '<rect x="301" y="301" width="158" height="158" rx="26"></rect>'
            + '</g>'
            + '</svg>';
    }

    /** Hijos <span> directos de un enlace, en orden. */
    function directSpans(anchor) {
        var found = [];
        var children = anchor.children;

        for (var i = 0; i < children.length; i++) {
            if (children[i].tagName && children[i].tagName.toLowerCase() === 'span') {
                found.push(children[i]);
            }
        }

        return found;
    }

    /** Clases de un elemento sin el modificador de "activo". */
    function baseClassName(element) {
        var raw = element.getAttribute('class') || '';

        return raw.split(/\s+/).filter(function (token) {
            return token !== '' && token !== 'active';
        }).join(' ');
    }

    /**
     * Deduce la estructura del enlace a partir de uno existente: clases del
     * <a>, del <span> que envuelve el icono, del <svg> y del <span> de texto.
     * Si el enlace de muestra no usa spans (Pterodactyl sin tema) se devuelven
     * null y el enlace se construye con texto plano.
     */
    function templateFrom(sample) {
        var spans = directSpans(sample);
        var iconBox = null;
        var labelBox = null;

        for (var i = 0; i < spans.length; i++) {
            if (iconBox === null && spans[i].querySelector('svg') !== null) {
                iconBox = spans[i];
                continue;
            }

            if (labelBox === null) {
                labelBox = spans[i];
            }
        }

        var explicitLabel = sample.querySelector('span.theme-editor-nav-label');

        if (explicitLabel !== null && explicitLabel !== iconBox) {
            labelBox = explicitLabel;
        }

        var svg = iconBox !== null ? iconBox.querySelector('svg') : null;

        return {
            anchorClass: baseClassName(sample),
            iconClass: iconBox !== null ? baseClassName(iconBox) : null,
            svgClass: svg !== null ? (svg.getAttribute('class') || '') : '',
            labelClass: labelBox !== null ? baseClassName(labelBox) : null
        };
    }

    /** Primer enlace del contenedor que no sea el nuestro. */
    function sampleAnchorIn(root, selector) {
        var anchors = root.querySelectorAll(selector);

        for (var i = 0; i < anchors.length; i++) {
            if (!anchors[i].hasAttribute(ATTR)) {
                return anchors[i];
            }
        }

        return null;
    }

    /**
     * Barra de navegacion clasica del servidor: el enlace de "Consola" apunta
     * exactamente a /server/<identificador>; su padre es el contenedor de la
     * navegacion siempre que tenga mas de un enlace.
     */
    function legacyNavigation(identifier) {
        var anchors = document.querySelectorAll('a[href="/server/' + identifier + '"]');

        for (var i = 0; i < anchors.length; i++) {
            var parent = anchors[i].parentElement;

            if (parent && parent.querySelectorAll('a').length >= 2) {
                return { container: parent, sample: anchors[i] };
            }
        }

        return null;
    }

    /**
     * Contenedor donde insertar el enlace y enlace de referencia para el
     * estilo. Se prioriza el grupo de addons del sidebar del tema.
     */
    function targetFor(identifier) {
        var group = document.querySelector(GROUP_SELECTOR);

        if (group !== null) {
            var sample = sampleAnchorIn(group, 'a')
                || sampleAnchorIn(document, THEME_ITEM_SELECTOR);

            if (sample !== null) {
                return { container: group, sample: sample };
            }
        }

        return legacyNavigation(identifier);
    }

    function detach(node) {
        if (node && node.parentNode) {
            node.parentNode.removeChild(node);
        }
    }

    /**
     * true solo si el usuario autenticado es el propietario del servidor y ese
     * servidor puede dividirse (no es una division). El backend decide; aqui no
     * se duplica ninguna regla de permisos.
     */
    function isAvailable(identifier) {
        if (Object.prototype.hasOwnProperty.call(availability, identifier)) {
            return availability[identifier];
        }

        if (typeof window.fetch !== 'function') {
            availability[identifier] = false;

            return false;
        }

        availability[identifier] = 'pending';

        window.fetch('/server/' + encodeURIComponent(identifier) + '/serversplitter/availability', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        }).then(function (payload) {
            availability[identifier] = !!(payload && payload.available);

            if (payload && typeof payload.url === 'string' && payload.url !== '') {
                links[identifier] = payload.url;
            }

            schedule();
        }).catch(function () {
            // Sesion caducada, extension desinstalada o red caida: no se
            // inyecta nada y el panel sigue funcionando igual.
            availability[identifier] = false;
        });

        return 'pending';
    }

    function hrefFor(identifier) {
        return links[identifier] || '/server/' + identifier + '/serversplitter';
    }

    function build(template, identifier) {
        var link = document.createElement('a');

        link.setAttribute(ATTR, identifier);
        link.setAttribute('data-theme-editor-id', EDITOR_ID);
        link.setAttribute('data-theme-editor-label', 'ServerSplitter sidebar item');
        link.setAttribute('draggable', 'false');
        link.className = template.anchorClass;
        link.href = hrefFor(identifier);

        if (template.iconClass !== null) {
            var iconBox = document.createElement('span');

            iconBox.setAttribute('data-theme-editor-icon', 'true');
            iconBox.className = template.iconClass;
            iconBox.innerHTML = iconMarkup(template.svgClass);
            link.appendChild(iconBox);
        }

        if (template.labelClass !== null) {
            var label = document.createElement('span');

            label.className = template.labelClass;
            label.textContent = LABEL;
            link.appendChild(label);
        } else {
            link.appendChild(document.createTextNode(LABEL));
        }

        return link;
    }

    /**
     * Marca el enlace como activo cuando estamos en su pagina. Los cambios se
     * escriben solo si hacen falta: el MutationObserver reacciona a cualquier
     * escritura y hacerlo a ciegas provocaria un bucle de re-render.
     */
    function syncActive(link, baseClass) {
        var path = link.pathname || '';
        var active = path !== '' && (
            window.location.pathname === path
            || window.location.pathname.indexOf(path + '/') === 0
        );
        var expected = active ? (baseClass + ' active').trim() : baseClass;

        if ((link.getAttribute('class') || '') !== expected) {
            link.setAttribute('class', expected);
        }

        if (active) {
            if (link.getAttribute('aria-current') !== 'page') {
                link.setAttribute('aria-current', 'page');
            }
        } else if (link.hasAttribute('aria-current')) {
            link.removeAttribute('aria-current');
        }
    }

    function apply() {
        pending = false;

        var identifier = currentIdentifier();
        var existing = document.querySelector('[' + ATTR + ']');

        if (identifier === null || isAvailable(identifier) !== true) {
            detach(existing);

            return;
        }

        var target = targetFor(identifier);

        if (target === null) {
            detach(existing);

            return;
        }

        var template = templateFrom(target.sample);

        // Se reutiliza el enlace ya inyectado salvo que haya cambiado de
        // servidor o que React haya reconstruido el contenedor.
        if (existing !== null) {
            if (existing.getAttribute(ATTR) === identifier
                && existing.parentNode === target.container) {
                var href = hrefFor(identifier);

                if (existing.getAttribute('href') !== href) {
                    existing.setAttribute('href', href);
                }

                syncActive(existing, template.anchorClass);

                return;
            }

            detach(existing);
        }

        var link = build(template, identifier);

        target.container.appendChild(link);
        syncActive(link, template.anchorClass);
    }

    function schedule() {
        if (pending) {
            return;
        }

        pending = true;

        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(apply);
        } else {
            window.setTimeout(apply, 50);
        }
    }

    function watchHistory(method) {
        var original = window.history[method];

        if (typeof original !== 'function') {
            return;
        }

        window.history[method] = function () {
            var result = original.apply(this, arguments);
            schedule();

            return result;
        };
    }

    function start() {
        schedule();

        if (typeof window.MutationObserver === 'function') {
            new window.MutationObserver(schedule).observe(document.documentElement, {
                childList: true,
                subtree: true
            });
        } else {
            window.setInterval(schedule, 1000);
        }

        window.addEventListener('popstate', schedule);
        watchHistory('pushState');
        watchHistory('replaceState');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();