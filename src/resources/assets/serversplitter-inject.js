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
 * El enlace se inserta directamente como hijo del grupo de addons, dentro del
 * arbol de React. React lo preserva en cada re-render gracias al atributo
 * data-serversplitter-link que identifica el elemento de forma unica.
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
    var SPLITTER_PATH = /^\/server\/[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\/serversplitter/;

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

    /**
     * true si la ruta actual es parte del fragmento de ServerSplitter
     * (/server/<id>/serversplitter o cualquier subruta dentro).
     */
    function isInServerSplitterRoute() {
        return SPLITTER_PATH.test(window.location.pathname);
    }

    /**
     * Icono propio (bloques divididos). SVG estatico, sin dependencias.
     *
     * El tamano se fija con atributos width/height + style inline (nunca
     * solo con la clase copiada de un icono vecino, ver hasIconSpan): esa
     * clase puede traer reglas especificas de ESE icono (p.ej. FontAwesome
     * codifica el ratio de aspecto del glifo en clases como "fa-w-14"), que
     * al aplicarse sobre nuestro dibujo (siempre cuadrado) podrian dejarlo
     * con un tamano incorrecto o en el peor caso invisible. El estilo
     * inline gana siempre sobre una clase externa, asi que esto garantiza
     * que el icono se vea sea cual sea el tema.
     */
    function iconMarkup(svgClass) {
        return '<svg aria-hidden="true" focusable="false" role="img"'
            + ' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"'
            + ' width="1em" height="1em" style="width:1em;height:1em;overflow:visible;flex-shrink:0"'
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

    /** true si alguno de los <span> directos del enlace envuelve un <svg> (patron de icono esperado por templateFrom). */
    function hasIconSpan(anchor) {
        var spans = directSpans(anchor);

        for (var i = 0; i < spans.length; i++) {
            if (spans[i].querySelector('svg') !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enlace del contenedor a usar de plantilla, que no sea el nuestro. Se
     * prefiere el primero con la estructura de icono esperada (span > svg),
     * no simplemente el primero del grupo: si otro addon del sidebar tiene
     * una estructura distinta (por ejemplo, un icono de fuente en vez de
     * svg), usarlo como plantilla hacia que templateFrom() confundiera cual
     * span era el icono y cual la etiqueta, dejando el enlace de
     * ServerSplitter sin icono y con el texto metido en la clase equivocada
     * (visto en produccion). Si ninguno encaja, se cae al primero de
     * todas formas para no quedarnos sin plantilla.
     */
    function sampleAnchorIn(root, selector) {
        var anchors = root.querySelectorAll(selector);
        var fallback = null;

        for (var i = 0; i < anchors.length; i++) {
            if (anchors[i].hasAttribute(ATTR)) {
                continue;
            }

            if (fallback === null) {
                fallback = anchors[i];
            }

            if (hasIconSpan(anchors[i])) {
                return anchors[i];
            }
        }

        return fallback;
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
     * Entre varios candidatos, el primero realmente visible (con tamano en
     * pantalla); si ninguno lo es todavia, el primero de todos de todas
     * formas, NUNCA null habiendo al menos un candidato.
     *
     * Por que preferir el visible: varios temas dejan en el DOM una copia
     * oculta del sidebar para otro breakpoint (version movil/escritorio,
     * ambas presentes a la vez, una tapada con display:none), y quedarse
     * sin mas con la primera que devuelve querySelector (sea o no la
     * visible) puede hacer que el portal (posicionado por coordenadas, ver
     * ensurePortal/renderPortal) calcule su posicion a partir de la copia
     * oculta y termine solapado con contenido real que no tiene nada que
     * ver (visto en produccion).
     *
     * Por que NO devolver null cuando el unico candidato mide 0x0: eso
     * tambien pasa de forma normal y transitoria justo despues de navegar,
     * mientras React todavia no ha terminado de pintar los hijos de ese
     * contenedor. La primera version de esta funcion devolvia null en ese
     * caso, y targetFor() lo interpretaba como "este grupo no existe en la
     * pagina", cayendo al hueco equivocado del sidebar (visto en
     * produccion: "Divisiones" enganchado bajo Dashboard/Consola en vez de
     * bajo Add-ons). Por eso solo se descarta un candidato con tamano cero
     * cuando hay OTRO con tamano real donde elegir; si no, se devuelve tal
     * cual (la siguiente llamada, en el proximo tick del MutationObserver,
     * ya lo encontrara con contenido).
     */
    function firstVisible(elements) {
        var fallback = null;

        for (var i = 0; i < elements.length; i++) {
            if (fallback === null) {
                fallback = elements[i];
            }

            var rect = elements[i].getBoundingClientRect();

            if (rect.width > 0 && rect.height > 0) {
                return elements[i];
            }
        }

        return fallback;
    }

    /**
     * Contenedor donde insertar el enlace y enlace de referencia para el
     * estilo. Se prioriza el grupo de addons del sidebar del tema.
     */
    function targetFor(identifier) {
        var group = firstVisible(document.querySelectorAll(GROUP_SELECTOR));

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
            var el = firstVisible(document.querySelectorAll(CONTENT_CANDIDATES[i]));

            if (el !== null) {
                log('area de contenido encontrada con el selector "' + CONTENT_CANDIDATES[i] + '"');

                return el;
            }
        }

        var node = firstVisible(document.querySelectorAll(GROUP_SELECTOR));

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
     * Hijos originales que React tenia dentro del area de contenido antes de
     * mostrar el fragmento, con su "display" previo, para poder devolverlos
     * a la vista tal cual al salir. Junto con currentContentEl, define si
     * estamos mostrando el fragmento en sitio.
     *
     * IMPORTANTE: estos nodos se ocultan con display:none EN SU SITIO, nunca
     * se mueven de padre (ni a un DocumentFragment en memoria ni a ningun
     * otro lado). Una version anterior si los movia, y provoco un crash real
     * en produccion: algunas animaciones del tema (CSSTransition, en el
     * bloque de limites del servidor) programan un removeChild() retrasado
     * con setTimeout tras su animacion de salida, contra el padre que el
     * nodo tenia en ESE momento. Si el nodo ya no colgaba de ahi porque
     * nuestro script lo habia movido entre medias, ese removeChild() tardio
     * fallaba con "NotFoundError: node to be removed is not a child of this
     * node", tiraba abajo el ErrorBoundary de React y forzaba un remount
     * completo (con la consiguiente tormenta de reconexiones del
     * websocket). Ocultando en sitio en vez de mover, el padre real de cada
     * nodo no cambia nunca, asi que cualquier removeChild() retrasado sigue
     * encontrandolo donde lo dejo.
     */
    var hiddenChildren = [];

    /**
     * Devuelve el area de contenido a como estaba antes de mostrar el
     * fragmento: se quita nuestro wrapper (enteramente nuestro, seguro de
     * eliminar) y se revierte el display de los hijos originales que se
     * habian ocultado. Ver hiddenChildren para el porque de este enfoque.
     */
    function restoreOriginalContent() {
        if (currentContentEl === null) {
            return;
        }

        detach(currentContentEl.querySelector('[data-serversplitter-fragment-root]'));

        for (var i = 0; i < hiddenChildren.length; i++) {
            hiddenChildren[i].node.style.display = hiddenChildren[i].display;
        }

        log('contenido original de la SPA restaurado');

        currentContentEl = null;
        hiddenChildren = [];
        swappedIdentifier = null;
        swapped = false;
    }

    /**
     * true justo antes de una llamada a pushState/replaceState hecha por
     * nosotros mismos: watchHistory() la consume y la deja en false, para no
     * confundirla con una navegacion ajena (ver watchHistory).
     */
    var suppressHistoryGuard = false;

    /**
     * true justo antes de un popstate sintetico disparado por nosotros
     * mismos (ver notifyRouter): el listener de popstate lo consume y lo
     * deja en false, para no confundirlo con "atras/adelante" real del
     * usuario y disparar restoreOriginalContent() sobre el fragmento que
     * acabamos de mostrar.
     */
    var suppressPopstateGuard = false;

    /**
     * pushState/replaceState nativos NO disparan ningun evento por si
     * solos, asi que el router de React (que se resincroniza escuchando
     * popstate) no se entera de que la URL cambio cuando la cambiamos
     * nosotros a mano. Visto en produccion: al entrar a "Divisiones" desde
     * "Files", el router seguia pensando que la ruta activa era Files y no
     * le quitaba la clase de activo, asi que ambos quedaban marcados a la
     * vez. Disparar un popstate sintetico justo despues empuja al router a
     * releer window.location y resincronizar su propio estado de ruta (y,
     * con el, el resaltado de los enlaces del sidebar que si controla el).
     */
    function notifyRouter() {
        if (typeof window.PopStateEvent !== 'function') {
            return;
        }

        suppressPopstateGuard = true;
        window.dispatchEvent(new window.PopStateEvent('popstate', { state: window.history.state }));
    }

    function ourPushState(state, href) {
        suppressHistoryGuard = true;
        window.history.pushState(state, '', href);
        notifyRouter();
    }

    function ourReplaceState(state, href) {
        suppressHistoryGuard = true;
        window.history.replaceState(state, '', href);
        notifyRouter();
    }

    /** Construye el HTML de un aviso de exito/error para prepender al fragmento. */
    function alertMarkup(status, message) {
        var cls = status === 'error' ? 'ss-alert--bad' : 'ss-alert--ok';
        var role = status === 'error' ? 'alert' : 'status';

        return '<div class="ss-alert ' + cls + '" role="' + role + '">'
            + message.replace(/&/g, '&').replace(/</g, '<').replace(/>/g, '>')
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
            if (currentContentEl === null) {
                // Primera vez que se muestra el fragmento en esta area: se
                // ocultan (no se mueven, ver hiddenChildren) los hijos que
                // React tenia puestos ahi.
                hiddenChildren = [];
                var kids = Array.prototype.slice.call(contentEl.children);

                for (var i = 0; i < kids.length; i++) {
                    hiddenChildren.push({ node: kids[i], display: kids[i].style.display });
                    kids[i].style.display = 'none';
                }
            } else {
                // Ya habia un fragmento nuestro mostrado (p.ej. cambiando de
                // servidor sin salir del todo): se quita solo el wrapper
                // anterior, que es enteramente nuestro. Los hijos originales
                // de React siguen ocultos tal cual.
                detach(contentEl.querySelector('[data-serversplitter-fragment-root]'));
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

            contentEl.appendChild(wrapper);
            contentEl.removeAttribute('aria-busy');
            currentContentEl = contentEl;
            swapped = true;
            swappedIdentifier = identifier;

            reinitFragmentScripts();
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
     * Pantalla de carga propia que tapa por completo la ventana mientras se
     * espera a que la SPA termine de montar antes de auto-abrir el
     * fragmento (ver autoOpenIfRequested). Sin esto, el usuario ve un
     * fogonazo de la pagina real (Console/Overview) antes de que cambie a
     * ServerSplitter, lo que da la sensacion de "panel fantasma" pegado por
     * fuera en vez de una integracion de verdad. No depende de ninguna
     * clase del tema ni de serversplitter.css: todo va inline, para que se
     * pueda mostrar de inmediato, antes incluso de que exista el area de
     * contenido que estamos esperando.
     */
    var loadingOverlay = null;

    function showLoadingOverlay() {
        if (loadingOverlay !== null) {
            return;
        }

        loadingOverlay = document.createElement('div');
        loadingOverlay.setAttribute('data-serversplitter-loading', '1');
        loadingOverlay.style.cssText = [
            'position:fixed', 'inset:0', 'z-index:2147483600',
            'background:#0f1115', 'color:#e6e9ee',
            'display:flex', 'flex-direction:column', 'align-items:center', 'justify-content:center',
            'gap:14px', 'font:500 14px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif'
        ].join(';');

        var spinner = document.createElement('div');
        spinner.style.cssText = [
            'width:34px', 'height:34px', 'border-radius:50%',
            'border:3px solid rgba(255,255,255,.18)', 'border-top-color:#2e7dd7',
            'animation:ss-spin 0.8s linear infinite'
        ].join(';');

        var keyframes = document.createElement('style');
        keyframes.textContent = '@keyframes ss-spin { to { transform: rotate(360deg); } }';

        var label = document.createElement('div');
        label.textContent = 'Cargando ServerSplitter...';

        loadingOverlay.appendChild(keyframes);
        loadingOverlay.appendChild(spinner);
        loadingOverlay.appendChild(label);
        document.body.appendChild(loadingOverlay);
    }

    function hideLoadingOverlay() {
        if (loadingOverlay === null) {
            return;
        }

        detach(loadingOverlay);
        loadingOverlay = null;
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

        // Tapa la pagina real (Console/Overview) desde el primer instante:
        // sin esto el usuario ve un fogonazo del contenido de verdad antes
        // de que aparezca ServerSplitter mientras se espera a que se
        // estabilice (ver mas abajo). Se quita en cuanto el fragmento esta
        // insertado, o si algo falla, para no dejar al usuario atascado.
        showLoadingOverlay();

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

            // Si la pagina de la que se viene (Subdomains, Files...) estaba
            // desplazada hacia abajo, ese scroll se queda tal cual al
            // sustituir el contenido: el fragmento aparece a mitad, con su
            // titulo fuera de la vista por arriba y el siguiente parrafo
            // cortado justo en el borde superior (visto en produccion).
            // loadIntoContent() ya hacia este reset para el clic en el
            // sidebar; aqui faltaba para la apertura automatica al
            // recargar/redirigir. Se hace ya (la pantalla de carga sigue
            // tapandolo todo) para que no se note el salto. Se resetean
            // tanto la ventana como el propio contenedor de contenido, por
            // si el tema hace scroll internamente en vez de en la ventana.
            window.scrollTo(0, 0);
            contentEl.scrollTop = 0;

            swapInFragment(contentEl, identifier, status, message).then(function () {
                hideLoadingOverlay();
            }).catch(function (error) {
                log('fallo al auto-abrir el fragmento:', error);
                hideLoadingOverlay();
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
                    hideLoadingOverlay();
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
     * Inserta el enlace directamente dentro del contenedor de addons del
     * sidebar (data-theme-layout-group="server:addons"). Al estar dentro
     * del arbol de React y heredar las clases del tema, se integra
     * perfectamente sin solapamientos ni roturas de estilo.
     *
     * React lo mantiene en cada re-render gracias al atributo
     * data-serversplitter-link que identifica el elemento de forma unica.
     * Si el contenedor se re-renderiza, React preserva el nodo porque tiene
     * ese atributo y lo reconoce como un elemento existente que no debe
     * destruir.
     */
    function renderDirectIntoAddons(target, template, identifier) {
        var addonsGroup = target.container;
        var existingLink = addonsGroup.querySelector('[data-serversplitter-link]');

        if (existingLink !== null && existingLink.getAttribute(ATTR) === identifier) {
            // El enlace ya existe para este servidor: solo sincroniza el href
            // y el estado de activo (puede haber cambiado tras una navegacion).
            var href = hrefFor(identifier);

            if (existingLink.getAttribute('href') !== href) {
                existingLink.setAttribute('href', href);
            }

            syncActive(existingLink, template.anchorClass);

            return;
        }

        // Primera vez o servidor diferente: crear el enlace y insertarlo
        // como ultimo hijo del grupo de addons.
        if (existingLink !== null) {
            detach(existingLink);
        }

        var link = build(template, identifier);
        addonsGroup.appendChild(link);

        log('servidor ' + identifier + ': enlace inyectado directamente en', addonsGroup);
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
            detach(fallbackLink());

            return;
        }

        var available = isAvailable(identifier);

        if (available !== true) {
            log('servidor ' + identifier + ': disponibilidad =', available);
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

            if (fallbackLink() === null) {
                document.body.appendChild(buildFloatingLink(identifier));
            }

            return;
        }

        // Encontrado un hueco de verdad: no hace falta el flotante de emergencia.
        detach(fallbackLink());

        var template = templateFrom(target.sample);

        renderDirectIntoAddons(target, template, identifier);
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
     * CORRECCION CRITICA: watchHistory ahora detecta si la navegacion es
     * DENTRO de la ruta /serversplitter. Si es asi, no restaura el contenido
     * (permite que los formularios, enlaces y cambios internos del fragmento
     * sucedan sin interruption). Solo restaura si la navegacion sale de
     * /serversplitter hacia otra seccion del panel.
     *
     * Esto evita el conflicto donde envios de formularios o clics internos
     * (que generan pushState a nuevas subrutas de /serversplitter/...) se
     * confundian con navegaciones ajenas y disparaban restoreOriginalContent(),
     * rompiendo la vista con error 404.
     */
    function watchHistory(method) {
        var original = window.history[method];

        if (typeof original !== 'function') {
            return;
        }

        window.history[method] = function (state, title, url) {
            var newPath = url || window.location.pathname;
            var isInServerSplitter = SPLITTER_PATH.test(newPath);
            var wasInServerSplitter = swapped && SPLITTER_PATH.test(window.location.pathname);

            // Si las dos llamadas son DENTRO de /serversplitter, simplemente
            // permite la navegacion sin restaurar nada: es un cambio interno
            // del fragmento (subpagina, tab, formulario...).
            if (suppressHistoryGuard) {
                suppressHistoryGuard = false;
            } else if (swapped && wasInServerSplitter && !isInServerSplitter) {
                // Solo restaura cuando se sale DE /serversplitter hacia FUERA.
                log('la SPA navega FUERA de ServerSplitter: restaurando el contenido original');
                restoreOriginalContent();
            }

            var result = original.apply(this, arguments);
            schedule();

            return result;
        };
    }

    /**
     * Escanea y ELIMINA AGRESIVAMENTE el contenedor de error 404 de React
     * cuando estamos en la ruta de ServerSplitter. React renderiza un
     * contenedor con clase .Fade__Container-sc-1p0gm8n-0 que muestra "404
     * Not Found" porque no reconoce la ruta /serversplitter.
     *
     * Esta funcion usa THREE estrategias en paralelo para garantizar
     * eliminacion total:
     *
     * 1. setInterval: Bucle continuo cada 50ms que busca y elimina el error
     * 2. MutationObserver: Reacciona inmediatamente a cambios en el DOM
     * 3. Limpieza de padres vacios: Elimina contenedores wrapper que quedan
     *    vacios tras remover el error
     *
     * Todo ocurre mientras estemos en /serversplitter. No afecta ningun
     * contenido de nuestro fragmento.
     */
    function watchFor404Container() {
        /**
         * Busca y ELIMINA (no solo oculta) elementos de error 404.
         * Retorna true si encontro y elimino algo, false si no.
         */
        function scanAndRemove404() {
            var removed = false;

            // Estrategia 1: La clase especifica del contenedor Fade de error
            var fadeContainers = document.querySelectorAll('.Fade__Container-sc-1p0gm8n-0');
            for (var i = 0; i < fadeContainers.length; i++) {
                var fade = fadeContainers[i];
                var text = fade.textContent || '';

                if ((text.indexOf('404') !== -1 || text.indexOf('Not Found') !== -1)
                    && !fade.hasAttribute('data-serversplitter-fragment-root')
                    && !fade.closest('[data-serversplitter-fragment-root]')) {

                    log('eliminando contenedor .Fade__Container-sc-1p0gm8n-0 con error 404:', fade);
                    detach(fade);
                    removed = true;
                }
            }

            // Estrategia 2: Cualquier elemento con clase que mencione Fade/Container
            var fadeVariants = document.querySelectorAll('[class*="Fade__Container"]');
            for (var j = 0; j < fadeVariants.length; j++) {
                var variant = fadeVariants[j];
                var variantText = variant.textContent || '';

                if ((variantText.indexOf('404') !== -1 || variantText.indexOf('Not Found') !== -1)
                    && !variant.hasAttribute('data-serversplitter-fragment-root')
                    && !variant.closest('[data-serversplitter-fragment-root]')) {

                    log('eliminando contenedor [class*="Fade__Container"] con error 404:', variant);
                    detach(variant);
                    removed = true;
                }
            }

            // Estrategia 3: Contenedores de error genéricos
            var errorBoundaries = document.querySelectorAll(
                '[class*="error-boundary"], ' +
                '[class*="ErrorBoundary"], ' +
                'div[role="alert"]'
            );
            for (var k = 0; k < errorBoundaries.length; k++) {
                var boundary = errorBoundaries[k];
                var boundaryText = boundary.textContent || '';

                if ((boundaryText.indexOf('404') !== -1 || boundaryText.indexOf('Not Found') !== -1)
                    && !boundary.hasAttribute('data-serversplitter-fragment-root')
                    && !boundary.closest('[data-serversplitter-fragment-root]')) {

                    log('eliminando error-boundary con error 404:', boundary);
                    detach(boundary);
                    removed = true;
                }
            }

            // Estrategia 4: Limpieza de contenedores padres que hayan quedado vacios
            // tras eliminar el error 404. Esto evita que wrappers div/section vacios
            // sigan ocupando espacio o interfiriendo con layouts.
            var potentialWrappers = document.querySelectorAll(
                '[class*="Fade"], [class*="Container"], [role="status"]'
            );
            for (var m = 0; m < potentialWrappers.length; m++) {
                var wrapper = potentialWrappers[m];

                // Si esta vacio (solo espacios/saltos de linea) y no es parte de nuestro fragmento
                if ((wrapper.textContent || '').trim() === ''
                    && !wrapper.hasAttribute('data-serversplitter-fragment-root')
                    && !wrapper.closest('[data-serversplitter-fragment-root]')
                    && wrapper.children.length === 0
                    && wrapper.parentNode !== null
                    && wrapper.parentNode !== document.body) {

                    log('eliminando contenedor vacio (probablemente wrapper del 404):', wrapper);
                    detach(wrapper);
                    removed = true;
                }
            }

            return removed;
        }

        // Estrategia A: MutationObserver para reaccionar inmediatamente a cambios
        if (typeof window.MutationObserver === 'function') {
            new window.MutationObserver(function (mutations) {
                // Solo actua si estamos en la ruta de ServerSplitter
                if (!isInServerSplitterRoute()) {
                    return;
                }

                scanAndRemove404();
            }).observe(document.documentElement, {
                childList: true,
                subtree: true
            });
        }

        // Estrategia B: setInterval para escaneo continuo cada 50ms
        // Esto caza errores 404 que aparezcan entre mutaciones o en navegadores
        // sin soporte MutationObserver (muy raro, pero seguro).
        window.setInterval(function () {
            if (!isInServerSplitterRoute()) {
                return;
            }

            scanAndRemove404();
        }, 50);
    }

    function start() {
        log('script cargado y arrancado en', window.location.pathname);

        // watchHistory() tiene que estar instalado ANTES de que nada llame a
        // ourReplaceState/ourPushState.
        watchHistory('pushState');
        watchHistory('replaceState');

        schedule();
        autoOpenIfRequested();

        // Observador para ocultar errores 404 cuando estamos en /serversplitter
        watchFor404Container();

        if (typeof window.MutationObserver === 'function') {
            new window.MutationObserver(schedule).observe(document.documentElement, {
                childList: true,
                subtree: true
            });
        } else {
            window.setInterval(schedule, 1000);
        }

        // "Atras"/"adelante" del navegador no pasa por pushState/replaceState
        // (dispara popstate directamente), asi que necesita su propio aviso
        // para restaurar el contenido original solo si sale de /serversplitter.
        window.addEventListener('popstate', function () {
            if (suppressPopstateGuard) {
                suppressPopstateGuard = false;
                schedule();

                return;
            }

            if (swapped && !isInServerSplitterRoute()) {
                log('atras/adelante del navegador: saliendo de ServerSplitter, restaurando el contenido original');
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