/**
 * ServerSplitter - integracion con el panel de cliente de Pterodactyl.
 *
 * El panel de cliente es una SPA de React con clases generadas por
 * styled-components, asi que no hay forma estable de "montar" un componente
 * desde fuera. Lo que se hace aqui es localizar el grupo de addons del sidebar
 * del servidor (el contenedor con data-theme-layout-group="server:addons", donde
 * viven Plugins, Server Config, Subdomains...) y clonar la estructura y las
 * clases de un enlace vecino (<a> > <span icono> + <span etiqueta>) para que
 * el nuestro herede el estilo exacto del tema activo.
 *
 * Ese enlace NO se inserta como hijo real de ese grupo: algunos temas
 * vuelven a renderizarlo muy a menudo (estadisticas de CPU/RAM por
 * websocket, por ejemplo) y React se lleva por delante cualquier nodo ajeno
 * en cada renderizado, en un bucle de insertar/borrar demasiado rapido para
 * llegar a pintarse. En su lugar se renderiza en un "portal" propio colgado
 * de <body> (fuera del arbol de React) y se posiciona por coordenadas
 * (position: fixed + getBoundingClientRect) justo debajo del grupo, para que
 * visualmente parezca un elemento mas de la lista. Ver ensurePortal/renderPortal.
 *
 * Si ese grupo no existe (Pterodactyl sin tema, o temas antiguos) se cae hacia
 * la barra de navegacion clasica del servidor; si tampoco se encuentra nada
 * reconocible, se muestra un enlace flotante fijo como ultimo recurso (ver
 * buildFloatingLink).
 *
 * Es tolerante a los re-render de React (MutationObserver), al scroll/resize
 * y a la navegacion por historial (pushState / replaceState / popstate). No
 * depende de ninguna libreria y se ejecuta una sola vez por documento.
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

    /** Candidatos para el area de contenido central de la SPA, en orden de preferencia. */
    var CONTENT_CANDIDATES = [
        '[data-theme-layout-group="content"]',
        '[data-theme-layout-group="server:content"]',
        'main'
    ];

    var pending = false;

    /** Contenedor actualmente sustituido por nuestro fragmento, o null. */
    var currentContentEl = null;

    /** Identificador del servidor mostrado actualmente en sitio, o null. */
    var swappedIdentifier = null;

    /** true mientras el area de contenido muestra nuestro fragmento en sitio. */
    var swapped = false;

    /**
     * Log con prefijo propio, visible en el filtro "Default" de la consola
     * del navegador (console.debug queda oculto ahi en algunos navegadores
     * bajo el filtro "Verbose", asi que se usa console.log a proposito).
     */
    function log() {
        if (window.console && typeof window.console.log === 'function') {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[ServerSplitter]');
            window.console.log.apply(window.console, args);
        }
    }

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

            log('se encontro ' + GROUP_SELECTOR + ' pero sin ningun <a> dentro para usar de plantilla');
        } else {
            log(GROUP_SELECTOR + ' no existe en esta pagina, probando la barra de navegacion clasica');
        }

        var legacy = legacyNavigation(identifier);

        if (legacy === null) {
            log('tampoco se encontro a[href="/server/' + identifier + '"] con hermanos');
        }

        return legacy;
    }

    function detach(node) {
        if (node && node.parentNode) {
            node.parentNode.removeChild(node);
        }
    }

    /**
     * Ultimo recurso: si no se encuentra ningun hueco reconocible en el
     * sidebar del tema (ni el grupo de addons ni la barra de navegacion
     * clasica), se muestra un enlace flotante fijo en la esquina. No usa
     * ninguna clase del tema ni de serversplitter.css: todos los estilos van
     * inline, asi que aparece si o si mientras haya disponibilidad. Es
     * deliberadamente visible (no una solucion final) para que quede claro
     * que el backend y el script funcionan, aunque la integracion visual con
     * el sidebar del tema necesite un selector distinto.
     */
    function buildFloatingLink(identifier) {
        var link = document.createElement('a');

        link.setAttribute(ATTR, identifier);
        link.setAttribute('data-serversplitter-fallback', '1');
        link.href = hrefFor(identifier);
        link.textContent = 'ServerSplitter: Divisiones';
        link.style.cssText = [
            'position:fixed', 'right:16px', 'bottom:16px', 'z-index:2147483647',
            'background:#2563eb', 'color:#fff', 'padding:10px 16px',
            'border-radius:8px', 'font:600 13px/1.2 system-ui,sans-serif',
            'text-decoration:none', 'box-shadow:0 4px 14px rgba(0,0,0,.35)'
        ].join(';');

        return link;
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
            log('respuesta de availability para ' + identifier + ':', payload);

            if (payload && typeof payload.url === 'string' && payload.url !== '') {
                links[identifier] = payload.url;
            }

            schedule();
        }).catch(function (error) {
            // Sesion caducada, extension desinstalada o red caida: no se
            // inyecta nada y el panel sigue funcionando igual.
            log('fallo la peticion de availability para ' + identifier + ':', error);
            availability[identifier] = false;
        });

        return 'pending';
    }

    function hrefFor(identifier) {
        return links[identifier] || '/server/' + identifier + '/serversplitter';
    }

    /**
     * Area de contenido central de la SPA (donde se pinta Console, Files,
     * Settings...). Se prueban selectores conocidos y, si ninguno existe, se
     * deduce por tamano: el hermano mas grande del contenedor del sidebar,
     * subiendo por los ancestros hasta encontrar uno.
     */
    function findContentContainer() {
        for (var i = 0; i < CONTENT_CANDIDATES.length; i++) {
            var el = document.querySelector(CONTENT_CANDIDATES[i]);

            if (el !== null) {
                log('area de contenido encontrada con el selector "' + CONTENT_CANDIDATES[i] + '"');

                return el;
            }
        }

        var node = document.querySelector(GROUP_SELECTOR);

        while (node !== null && node.parentElement !== null && node !== document.body) {
            var parent = node.parentElement;
            var best = null;
            var bestArea = 40000; // ~200x200px minimo: descarta botones/iconos sueltos.

            for (var j = 0; j < parent.children.length; j++) {
                var sibling = parent.children[j];

                if (sibling === node) {
                    continue;
                }

                var rect = sibling.getBoundingClientRect();
                var area = rect.width * rect.height;

                if (area > bestArea) {
                    bestArea = area;
                    best = sibling;
                }
            }

            if (best !== null) {
                log('area de contenido deducida por tamano (hermano de', node, '):', best);

                return best;
            }

            node = parent;
        }

        return null;
    }

    /** Carga (o crea) el CSS de la extension, necesario para el fragmento inyectado. */
    function ensureFragmentCss() {
        if (document.querySelector('link[data-serversplitter-css]') !== null) {
            return;
        }

        var link = document.createElement('link');
        link.setAttribute('data-serversplitter-css', '1');
        link.rel = 'stylesheet';
        link.href = '/extensions/serversplitter/serversplitter.css';
        document.head.appendChild(link);
    }

    /**
     * Vuelve a cargar serversplitter.js para que confirme los formularios y
     * calcule la vista previa de recursos del fragmento recien insertado
     * (los listeners del fragmento anterior murieron con el, al sustituir
     * el innerHTML).
     */
    function reinitFragmentScripts() {
        var old = document.querySelectorAll('script[data-serversplitter-fragment-script]');
        Array.prototype.forEach.call(old, function (el) {
            el.parentNode.removeChild(el);
        });

        var script = document.createElement('script');
        script.src = '/extensions/serversplitter/serversplitter.js';
        script.setAttribute('data-serversplitter-fragment-script', '1');
        document.body.appendChild(script);
    }

    /**
     * Nodos originales que React tenia dentro del area de contenido antes de
     * sustituirla por nuestro fragmento, guardados (no destruidos) para
     * devolverlos tal cual al salir. Junto con currentContentEl, define si
     * estamos mostrando el fragmento en sitio.
     */
    var savedOriginalContent = null;

    /**
     * Devuelve el area de contenido a como estaba antes de mostrar el
     * fragmento: se quita nuestro wrapper y se reinsertan los nodos
     * originales de React exactamente donde estaban. Al no haberlos
     * destruido nunca (solo movidos a un DocumentFragment en memoria),
     * React sigue reconociendolos como suyos y puede volver a renderizar
     * sobre ellos con normalidad.
     */
    function restoreOriginalContent() {
        if (currentContentEl === null) {
            return;
        }

        currentContentEl.innerHTML = ''; // solo contiene nuestro wrapper: seguro de vaciar.

        if (savedOriginalContent !== null) {
            currentContentEl.appendChild(savedOriginalContent);
        }

        log('contenido original de la SPA restaurado');

        currentContentEl = null;
        savedOriginalContent = null;
        swappedIdentifier = null;
        swapped = false;
    }

    /**
     * true justo antes de una llamada a pushState/replaceState hecha por
     * nosotros mismos: watchHistory() la consume y la deja en false, para no
     * confundirla con una navegacion ajena (ver watchHistory).
     */
    var suppressHistoryGuard = false;

    function ourPushState(state, href) {
        suppressHistoryGuard = true;
        window.history.pushState(state, '', href);
    }

    function ourReplaceState(state, href) {
        suppressHistoryGuard = true;
        window.history.replaceState(state, '', href);
    }

    /** Construye el HTML de un aviso de exito/error para prepender al fragmento. */
    function alertMarkup(status, message) {
        var cls = status === 'error' ? 'ss-alert--bad' : 'ss-alert--ok';
        var role = status === 'error' ? 'alert' : 'status';

        return '<div class="ss-alert ' + cls + '" role="' + role + '">'
            + message.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            + '</div>';
    }

    /**
     * Sustituye el area de contenido de la SPA por el fragmento HTML del
     * servidor indicado, sin recargar la pagina. Los nodos que hubiera
     * dentro (los que pinto React) se guardan sin destruir, para poder
     * devolverlos intactos en restoreOriginalContent(). No toca el
     * historial: eso lo decide quien llama (ver loadIntoContent y
     * autoOpenIfRequested), segun si es una navegacion nueva o continuar una
     * ya en marcha.
     *
     * @param {string|null} message Aviso de exito/error a mostrar arriba del
     *   fragmento (por ejemplo, tras crear o eliminar una division).
     */
    function swapInFragment(contentEl, identifier, status, message) {
        ensureFragmentCss();
        contentEl.setAttribute('aria-busy', 'true');

        return window.fetch('/server/' + encodeURIComponent(identifier) + '/serversplitter/fragment', {
            credentials: 'same-origin',
            headers: { Accept: 'text/html' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.text();
        }).then(function (html) {
            // Si ya habia un fragmento nuestro mostrado (p.ej. cambiando de
            // servidor sin salir del todo), se descarta: no hay nada de
            // React que conservar ahi.
            if (swapped) {
                currentContentEl = null;
                savedOriginalContent = null;
            }

            if (currentContentEl === null) {
                savedOriginalContent = document.createDocumentFragment();

                while (contentEl.firstChild) {
                    savedOriginalContent.appendChild(contentEl.firstChild);
                }
            }

            var wrapper = document.createElement('div');
            wrapper.setAttribute('data-serversplitter-fragment-root', '1');
            wrapper.innerHTML = html;

            // El aviso se inserta DENTRO del contenedor .ss-fragment.ss-container
            // que ya trae el fragmento (no antes, como hermano suyo): asi
            // hereda el mismo ancho maximo y centrado que el resto.
            if (message) {
                var alertHost = document.createElement('div');
                alertHost.innerHTML = alertMarkup(status, message);

                var target = wrapper.firstElementChild || wrapper;
                target.insertBefore(alertHost.firstElementChild, target.firstChild);
            }

            contentEl.innerHTML = '';
            contentEl.appendChild(wrapper);
            contentEl.removeAttribute('aria-busy');
            currentContentEl = contentEl;
            swapped = true;
            swappedIdentifier = identifier;

            reinitFragmentScripts();
            // Reposiciona el portal del sidebar de inmediato (no espera al
            // siguiente scroll/mutacion): el area de contenido puede haber
            // cambiado de alto y, con ella, el punto exacto donde acaba el
            // sidebar en pantalla.
            schedule();
            log('contenido sustituido en sitio para ' + identifier + ' (sin recargar la pagina)');
        });
    }

    /**
     * Version para un clic del usuario en el enlace del sidebar: aniade una
     * entrada nueva al historial (pushState) y, si algo falla, se cae a una
     * navegacion normal a la pagina completa.
     */
    function loadIntoContent(contentEl, identifier, href) {
        swapInFragment(contentEl, identifier, null, null).then(function () {
            ourPushState({ serversplitter: true }, href);
            window.scrollTo(0, 0);
        }).catch(function (error) {
            log('fallo al cargar el fragmento, navegando normal a ' + href + ':', error);
            window.location.href = href;
        });
    }

    /**
     * "Firma" barata del contenido de un elemento, para detectar cuando deja
     * de cambiar sin comparar el HTML entero en cada intento.
     */
    function contentSignature(el) {
        return el.children.length + ':' + el.textContent.length;
    }

    /**
     * Al cargar la pagina (F5, marcador, pestana nueva, o tras enviar un
     * formulario de crear/eliminar division) puede venir un aviso en la URL:
     *   ?ss=1                 abrir ServerSplitter para este servidor
     *   &ss_ok=mensaje        aviso de exito a mostrar
     *   &ss_error=mensaje     aviso de error a mostrar
     *
     * SplitterController::show()/back() redirigen aqui en vez de renderizar
     * una pagina propia: una carga real no puede "continuar" dentro de la
     * SPA, tiene que arrancar de cero, asi que se deja que Pterodactyl monte
     * su pagina normal del servidor y, cuando esta lista, se sustituye por
     * el fragmento.
     *
     * "Lista" no es solo "el area de contenido existe": justo despues de
     * montar, React todavia esta cargando ahi dentro (un <Spinner> mientras
     * pide los datos del servidor). Sustituir el contenido en ese momento le
     * arranca el DOM de debajo mientras todavia lo esta usando y lo hace
     * fallar (visto en produccion: "Uncaught" en <Spinner>, seguido de que
     * React reconstruye el arbol entero desde cero y reconecta el websocket
     * en bucle hasta chocar con el limite de peticiones, 429). Por eso se
     * espera a que el contenido lleve varios intentos seguidos SIN cambiar
     * (contentSignature estable) antes de tocar nada: eso indica que React
     * ya termino de cargar esa vista y no va a seguir escribiendo ahi.
     */
    function autoOpenIfRequested() {
        var params = new URLSearchParams(window.location.search);

        if (params.get('ss') !== '1') {
            return;
        }

        var identifier = currentIdentifier();

        if (identifier === null) {
            return;
        }

        var status = params.has('ss_error') ? 'error' : 'ok';
        var message = params.get('ss_error') || params.get('ss_ok') || null;

        var cleanHref = '/server/' + encodeURIComponent(identifier) + '/serversplitter';
        var attempts = 0;
        var maxAttempts = 150; // ~15s de margen total a 100ms por intento.
        var stableTicks = 0;
        var requiredStableTicks = 5; // ~500ms sin cambios en el contenido.
        var lastSignature = null;

        var doSwap = function (contentEl) {
            // Se limpia la URL (quita ?ss=1&...) con replaceState: esto no es
            // una navegacion nueva de verdad, es continuar la que ya traia la
            // URL, asi que no debe anadir una entrada al historial.
            ourReplaceState({ serversplitter: true }, cleanHref);

            swapInFragment(contentEl, identifier, status, message).catch(function (error) {
                log('fallo al auto-abrir el fragmento:', error);
            });
        };

        var tryOpen = function () {
            var contentEl = findContentContainer();

            if (contentEl === null) {
                attempts++;

                if (attempts < maxAttempts) {
                    window.setTimeout(tryOpen, 100);
                } else {
                    log('no se pudo auto-abrir ServerSplitter: no se encontro el area de contenido a tiempo');
                }

                return;
            }

            var signature = contentSignature(contentEl);

            if (signature !== lastSignature) {
                lastSignature = signature;
                stableTicks = 0;
            } else {
                stableTicks++;
            }

            attempts++;

            if (stableTicks >= requiredStableTicks) {
                log('contenido de la SPA estable, auto-abriendo ServerSplitter para ' + identifier);
                doSwap(contentEl);

                return;
            }

            if (attempts < maxAttempts) {
                window.setTimeout(tryOpen, 100);

                return;
            }

            log('el contenido de la SPA no se termino de estabilizar; se abre igualmente para no dejar la pagina colgada');
            doSwap(contentEl);
        };

        tryOpen();
    }

    /**
     * Clic en nuestro propio enlace del sidebar: si se puede localizar el
     * area de contenido, se sustituye en sitio (sin recarga). Si no, se deja
     * la navegacion normal tal cual (misma pagina de siempre, con su propio
     * layout completo).
     */
    function handleLinkClick(event) {
        if (event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        var identifier = event.currentTarget.getAttribute(ATTR);
        var href = event.currentTarget.getAttribute('href') || '';

        if (!identifier) {
            return;
        }

        // Ya se esta mostrando este mismo servidor en sitio: no hay nada que
        // volver a sustituir (y hacerlo perderia los nodos originales de
        // React guardados para restaurar mas tarde, ver loadIntoContent).
        if (swapped && swappedIdentifier === identifier) {
            event.preventDefault();

            return;
        }

        var contentEl = findContentContainer();

        if (contentEl === null) {
            log('no se encontro el area de contenido de la SPA; navegando de forma normal a ' + href);

            return;
        }

        event.preventDefault();
        loadIntoContent(contentEl, identifier, href);
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

        link.addEventListener('click', handleLinkClick);

        return link;
    }

    /**
     * "Portal" fuera del arbol de React: un <div> propio colgado
     * directamente de <body>, nunca dentro del contenedor que gestiona
     * React. Se posiciona por coordenadas (position: fixed +
     * getBoundingClientRect) justo debajo del grupo de addons del sidebar,
     * para que visualmente parezca un elemento mas de la lista sin serlo
     * realmente en el DOM.
     *
     * Por que no se inserta como hijo real de ese contenedor: algunos temas
     * vuelven a renderizar ese grupo muy a menudo (por ejemplo al llegar
     * estadisticas de CPU/RAM por websocket) y en cada renderizado React
     * reconstruye sus hijos y se lleva por delante cualquier nodo ajeno que
     * se le haya anadido a mano, en un bucle de insertar/borrar demasiado
     * rapido para que llegue a pintarse en pantalla.
     */
    var portal = null;

    function ensurePortal() {
        if (portal !== null) {
            return portal;
        }

        portal = document.createElement('div');
        portal.setAttribute('data-serversplitter-portal', '1');
        portal.style.cssText = 'position:fixed;z-index:2147483000;display:none;';
        document.body.appendChild(portal);

        return portal;
    }

    /**
     * Posiciona el portal justo debajo del contenedor de referencia (el
     * grupo de addons, o la barra de navegacion clasica), con su mismo
     * ancho, y sincroniza el enlace de dentro con la plantilla y el href
     * actuales.
     */
    function renderPortal(target, template, identifier) {
        var host = ensurePortal();
        var rect = target.container.getBoundingClientRect();

        host.style.top = Math.round(rect.bottom) + 'px';
        host.style.left = Math.round(rect.left) + 'px';
        host.style.width = Math.round(rect.width) + 'px';
        host.style.display = rect.width > 0 ? '' : 'none';

        var link = host.firstElementChild;

        if (link === null || link.getAttribute(ATTR) !== identifier) {
            log('servidor ' + identifier + ': enlace creado, posicionado bajo', target.container);
            host.innerHTML = '';
            link = build(template, identifier);
            host.appendChild(link);
        } else {
            var href = hrefFor(identifier);

            if (link.getAttribute('href') !== href) {
                link.setAttribute('href', href);
            }

            if (link.className !== template.anchorClass) {
                link.className = template.anchorClass;
            }
        }

        syncActive(link, template.anchorClass);
    }

    function hidePortal() {
        if (portal !== null) {
            portal.style.display = 'none';
        }
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

    function fallbackLink() {
        return document.querySelector('[data-serversplitter-fallback]');
    }

    function apply() {
        pending = false;

        var identifier = currentIdentifier();

        if (identifier === null) {
            log('no estamos en una pagina de servidor (pathname: ' + window.location.pathname + ')');
            hidePortal();
            detach(fallbackLink());

            return;
        }

        var available = isAvailable(identifier);

        if (available !== true) {
            log('servidor ' + identifier + ': disponibilidad =', available);
            hidePortal();
            detach(fallbackLink());

            return;
        }

        var target = targetFor(identifier);

        if (target === null) {
            log(
                'servidor ' + identifier + ': disponible, pero no se encontro ningun hueco en el sidebar ' +
                '(ni ' + GROUP_SELECTOR + ' ni la barra de navegacion clasica). Se muestra el enlace flotante ' +
                'de emergencia; manda una captura del sidebar completo (Elements de DevTools) para ajustar el selector.'
            );

            hidePortal();

            if (fallbackLink() === null) {
                document.body.appendChild(buildFloatingLink(identifier));
            }

            return;
        }

        // Encontrado un hueco de verdad: no hace falta el flotante de emergencia.
        detach(fallbackLink());

        var template = templateFrom(target.sample);

        renderPortal(target, template, identifier);
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

    /**
     * pushState/replaceState son el unico mecanismo que cualquier SPA usa
     * para cambiar de ruta sin recargar la pagina (lo use un <a>, un
     * elemento sin href con onClick, el teclado...), asi que interceptarlos
     * aqui cubre CUALQUIER forma en la que el usuario navegue a otro sitio
     * mientras se ve nuestro fragmento, sin tener que adivinar como esta
     * implementado el sidebar del tema.
     *
     * Nuestras propias llamadas (ourPushState/ourReplaceState) se marcan de
     * antemano con suppressHistoryGuard, asi que nunca se confunden con una
     * navegacion ajena aunque el orden exacto de "cuando se activa swapped"
     * cambie entre los distintos sitios que llaman a esto.
     */
    function watchHistory(method) {
        var original = window.history[method];

        if (typeof original !== 'function') {
            return;
        }

        window.history[method] = function () {
            if (suppressHistoryGuard) {
                suppressHistoryGuard = false;
            } else if (swapped) {
                log('la SPA navega mientras se mostraba el fragmento: restaurando el contenido original antes de continuar');
                restoreOriginalContent();
            }

            var result = original.apply(this, arguments);
            schedule();

            return result;
        };
    }

    function start() {
        log('script cargado y arrancado en', window.location.pathname);

        // watchHistory() tiene que estar instalado ANTES de que nada llame a
        // ourReplaceState/ourPushState (autoOpenIfRequested puede hacerlo de
        // forma sincrona si el area de contenido ya existe en el primer
        // intento), si no la bandera suppressHistoryGuard no tendria ningun
        // wrapper que la consumiera.
        watchHistory('pushState');
        watchHistory('replaceState');

        schedule();
        autoOpenIfRequested();

        if (typeof window.MutationObserver === 'function') {
            new window.MutationObserver(schedule).observe(document.documentElement, {
                childList: true,
                subtree: true
            });
        } else {
            window.setInterval(schedule, 1000);
        }

        // El portal se posiciona por coordenadas: hay que recalcularlas si
        // la pagina o el propio sidebar hacen scroll, o si cambia el
        // tamano de la ventana. capture:true en scroll para enterarse
        // tambien del scroll interno del sidebar (los eventos de scroll no
        // burbujean, pero si se capturan bajando desde window).
        window.addEventListener('scroll', schedule, true);
        window.addEventListener('resize', schedule);

        // "Atras"/"adelante" del navegador no pasa por pushState/replaceState
        // (dispara popstate directamente), asi que necesita su propio aviso
        // para restaurar el contenido original antes de que la SPA
        // reaccione al cambio de URL.
        window.addEventListener('popstate', function () {
            if (swapped) {
                log('atras/adelante del navegador mientras se mostraba el fragmento: restaurando el contenido original');
                restoreOriginalContent();
            }

            schedule();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();