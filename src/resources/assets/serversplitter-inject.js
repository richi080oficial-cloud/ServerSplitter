/**
 * ServerSplitter - integracion con el panel de cliente de Pterodactyl.
 *
 * El panel de cliente es una SPA de React con clases generadas por
 * styled-components, asi que no hay forma estable de "montar" un componente
 * desde fuera. Lo que se hace aqui es localizar la barra de navegacion del
 * servidor (el contenedor del enlace a /server/<identificador>) y anadir un
 * enlace propio, copiando las clases del enlace existente para que el estilo
 * coincida con el tema activo.
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
    var SERVER_PATH = /^\/server\/([A-Za-z0-9]+(?:-[A-Za-z0-9]+)*)/;

    var pending = false;

    /** Identificador corto del servidor abierto, o null si no estamos en uno. */
    function currentIdentifier() {
        var match = SERVER_PATH.exec(window.location.pathname);

        return match ? match[1] : null;
    }

    /**
     * Busca la barra de navegacion del servidor. El enlace de "Consola" apunta
     * exactamente a /server/<identificador>; su padre es el contenedor de la
     * navegacion siempre que tenga mas de un enlace.
     */
    function navigationFor(identifier) {
        var anchors = document.querySelectorAll('a[href="/server/' + identifier + '"]');

        for (var i = 0; i < anchors.length; i++) {
            var parent = anchors[i].parentElement;

            if (parent && parent.querySelectorAll('a').length >= 2) {
                return { container: parent, sample: anchors[i] };
            }
        }

        return null;
    }

    /** Clases del enlace de referencia, sin el modificador de "activo". */
    function baseClassName(sample) {
        var raw = typeof sample.className === 'string' ? sample.className : '';

        return raw.split(/\s+/).filter(function (token) {
            return token !== '' && token !== 'active';
        }).join(' ');
    }

    function detach(node) {
        if (node && node.parentNode) {
            node.parentNode.removeChild(node);
        }
    }

    function apply() {
        pending = false;

        var identifier = currentIdentifier();
        var existing = document.querySelector('[' + ATTR + ']');

        if (identifier === null) {
            detach(existing);

            return;
        }

        if (existing !== null) {
            if (existing.getAttribute(ATTR) === identifier) {
                return;
            }

            detach(existing);
        }

        var target = navigationFor(identifier);

        if (target === null) {
            return;
        }

        var link = document.createElement('a');
        link.setAttribute(ATTR, identifier);
        link.className = baseClassName(target.sample);
        link.href = '/serversplitter/' + identifier;
        link.textContent = LABEL;

        target.container.appendChild(link);
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