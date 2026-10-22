/**
 * Panel del teatro
 *
 * Lista a la izquierda, ficha a la derecha. Todo el DOM se construye con
 * `textContent` y atributos: ningún dato que escriba el teatro se interpreta
 * como HTML. Los datos van y vienen de `cloudari/v1/panel` con `wp.apiFetch`,
 * que ya añade el nonce de la sesión.
 */
(function () {
    'use strict';

    var cfg = window.cloudariPanel || {};
    var root = document.querySelector('[data-cld-panel]');

    if (!root || !window.wp || !wp.apiFetch) {
        return;
    }

    var main = root.querySelector('[data-cld-main]');
    var live = root.querySelector('[data-cld-live]');
    var API = cfg.restPath || '/cloudari/v1/panel';
    var SVG_NS = 'http://www.w3.org/2000/svg';

    var DEFAULT_RULES = { weekday_start: '15:00', weekday_end: '21:00', weekend_start: '12:00', weekend_end: '21:00' };

    var MODES = [
        { value: 'sessions', name: 'En fechas concretas', desc: 'Una o varias funciones, cada una con su día y hora.' },
        { value: 'range', name: 'Varios días seguidos', desc: 'Mercados, exposiciones… abiertos del primer al último día.' },
        { value: 'permanent', name: 'Siempre, sin fechas', desc: 'Espacios o servicios fijos. Sale en la cartelera, no en el calendario.' }
    ];

    var state = {
        section: '',
        data: null,
        loadError: '',
        heroes: { key: '', draft: [], savedJson: '', savedOrder: [], selected: 0, moved: {}, errors: {}, saving: false, view: 'list' },
        events: { selected: null, draft: null, savedJson: '', errors: {}, saving: false, query: '', view: 'list' }
    };

    var newIds = 0;

    /* ==============================
     *  Utilidades
     * ============================== */

    function h(tag, attrs) {
        var el = document.createElement(tag);
        var children = Array.prototype.slice.call(arguments, 2);

        Object.keys(attrs || {}).forEach(function (key) {
            var value = attrs[key];

            if (value === null || value === undefined || value === false) {
                return;
            }

            if (key === 'class') {
                el.className = value;
            } else if (key === 'text') {
                el.textContent = value;
            } else if (key.indexOf('on') === 0 && typeof value === 'function') {
                el.addEventListener(key.slice(2).toLowerCase(), value);
            } else if (key === 'value') {
                el.value = value;
            } else if (key === 'checked') {
                el.checked = !!value;
            } else {
                el.setAttribute(key, value === true ? '' : String(value));
            }
        });

        append(el, children);

        return el;
    }

    function append(parent, children) {
        children.forEach(function (child) {
            if (child === null || child === undefined || child === false) {
                return;
            }

            if (Array.isArray(child)) {
                append(parent, child);
            } else if (typeof child === 'string' || typeof child === 'number') {
                parent.appendChild(document.createTextNode(String(child)));
            } else {
                parent.appendChild(child);
            }
        });
    }

    var ICONS = {
        plus: ['M12 5v14', 'M5 12h14'],
        up: ['M12 19V5', 'M6 11l6-6 6 6'],
        down: ['M12 5v14', 'M18 13l-6 6-6-6'],
        trash: ['M4 7h16', 'M10 11v6', 'M14 11v6', 'M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12', 'M9 7V4h6v3'],
        image: ['M4 5h16v14H4z', 'M4 16l5-5 4 4 3-3 4 4', 'M15.5 9.5h.01'],
        video: ['M4 6h11v12H4z', 'M15 10l5-3v10l-5-3'],
        check: ['M5 12.5l4.5 4.5L19 7'],
        alert: ['M12 8v5', 'M12 16.5h.01', 'M10.3 3.9L2.6 17.3A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.7L13.7 3.9a2 2 0 0 0-3.4 0z'],
        external: ['M14 4h6v6', 'M20 4l-9 9', 'M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5'],
        back: ['M15 5l-7 7 7 7'],
        posters: ['M3 5h18v11H3z', 'M7 20h10'],
        calendar: ['M4 6h16v14H4z', 'M4 10h16', 'M8 3v5', 'M16 3v5']
    };

    function icon(name) {
        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'cld-icon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');

        (ICONS[name] || []).forEach(function (d) {
            var path = document.createElementNS(SVG_NS, 'path');
            path.setAttribute('d', d);
            svg.appendChild(path);
        });

        return svg;
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function announce(message) {
        live.textContent = '';
        window.setTimeout(function () {
            live.textContent = message;
        }, 50);
    }

    var toastTimer = null;

    function toast(message) {
        var old = document.querySelector('.cld-toast');

        if (old) {
            old.remove();
        }

        var el = h('div', { class: 'cld-toast', role: 'status' }, icon('check'), h('span', { text: message }));
        // Dentro del panel, no en <body>: fuera de .cld-panel no existen sus colores
        // (--ink, --lime…) y el aviso salía como una caja blanca sin texto visible.
        root.appendChild(el);
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            el.remove();
        }, 5000);
    }

    function api(path, options) {
        return wp.apiFetch(Object.assign({ path: API + path }, options || {}));
    }

    function errorFields(error) {
        return (error && error.data && error.data.fields) || {};
    }

    function errorMessage(error) {
        if (error && error.message) {
            return error.message;
        }

        return 'No se ha podido conectar. Revisa la conexión a internet e inténtalo de nuevo.';
    }

    /**
     * Quien copia "www.teatro.es" sin el https:// no debería toparse con un error:
     * se completa solo. Lo que no parece una dirección se deja tal cual para que
     * la validación lo explique.
     */
    function normalizeUrl(value) {
        var url = String(value || '').trim();

        if (url && !/^[a-z][a-z0-9+.-]*:/i.test(url) && /^[^\s\/]+\.[^\s]{2,}/.test(url)) {
            url = 'https://' + url.replace(/^\/+/, '');
        }

        return url;
    }

    function today() {
        var d = new Date();
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };

        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    /**
     * Diálogo de confirmación nativo (`<dialog>`): atrapa el foco, cierra con
     * Escape y devuelve el foco al botón que lo abrió.
     */
    function confirmDialog(options) {
        return new Promise(function (resolve) {
            var opener = document.activeElement;
            var dialog = h('dialog', { class: 'cld-dialog', 'aria-labelledby': 'cld-dialog-title' });
            var done = function (value) {
                dialog.close();
                dialog.remove();

                if (opener && typeof opener.focus === 'function' && document.body.contains(opener)) {
                    opener.focus();
                }

                resolve(value);
            };

            var actions = h('div', { class: 'cld-dialog__actions' });

            options.actions.forEach(function (action) {
                actions.appendChild(h('button', {
                    type: 'button',
                    class: 'cld-btn ' + (action.className || ''),
                    onClick: function () { done(action.value); }
                }, action.label));
            });

            dialog.appendChild(h('div', { class: 'cld-dialog__body' },
                h('h2', { class: 'cld-dialog__title', id: 'cld-dialog-title', text: options.title }),
                options.text ? h('p', { class: 'cld-dialog__text', text: options.text }) : null
            ));
            dialog.appendChild(actions);
            dialog.addEventListener('cancel', function (event) {
                event.preventDefault();
                done('cancel');
            });

            root.appendChild(dialog);
            dialog.showModal();

            // El foco empieza en la opción segura (la última: "Cancelar" / "Seguir aquí").
            var buttons = actions.querySelectorAll('button');
            buttons[buttons.length - 1].focus();
        });
    }

    /**
     * Selector de la Mediateca de WordPress.
     *
     * @param {string[]} types `['image']` o `['image', 'video']`.
     */
    function pickMedia(title, types) {
        return new Promise(function (resolve) {
            if (!window.wp || !wp.media) {
                resolve(null);
                return;
            }

            var frame = wp.media({
                title: title,
                button: { text: 'Usar este archivo' },
                library: { type: types },
                multiple: false
            });

            var picked = false;

            frame.on('select', function () {
                var a = frame.state().get('selection').first().toJSON();
                var isVideo = a.type === 'video';
                var sizes = a.sizes || {};
                var thumb = '';

                if (!isVideo) {
                    thumb = (sizes.medium_large || sizes.large || sizes.full || {}).url || a.url;
                }

                picked = true;
                resolve({ id: a.id, url: a.url, type: isVideo ? 'video' : 'image', thumb: thumb, name: a.title || a.filename || '' });
            });

            frame.on('close', function () {
                window.setTimeout(function () {
                    if (!picked) {
                        resolve(null);
                    }
                }, 0);
            });

            frame.open();
        });
    }

    function mediaPreview(media, poster) {
        if (!media) {
            return null;
        }

        if (media.type === 'video') {
            var src = media.url.indexOf('#') === -1 ? media.url + '#t=0.1' : media.url;

            return h('video', {
                src: src,
                poster: poster && poster.thumb ? poster.thumb : null,
                muted: true,
                playsinline: true,
                preload: 'metadata',
                'aria-hidden': 'true'
            });
        }

        return h('img', { src: media.thumb || media.url, alt: '', loading: 'lazy', decoding: 'async' });
    }

    /* ==============================
     *  Navegación y guardas
     * ============================== */

    function isDirty(section) {
        if (section === 'heroes') {
            return state.heroes.key !== '' && JSON.stringify(heroPayload()) !== state.heroes.savedJson;
        }

        if (section === 'events') {
            return state.events.draft !== null && JSON.stringify(state.events.draft) !== state.events.savedJson;
        }

        return false;
    }

    /**
     * Antes de cambiar de cartel, de evento o de sección con cambios a medias.
     * Resuelve `true` si se puede seguir.
     */
    function guard(section) {
        if (!isDirty(section)) {
            return Promise.resolve(true);
        }

        return confirmDialog({
            title: 'Tienes cambios sin guardar',
            text: section === 'heroes'
                ? 'Si sigues sin guardar, los carteles volverán a como están en la web.'
                : 'Si sigues sin guardar, se perderá lo que has cambiado en este evento.',
            actions: [
                { label: 'Guardar y seguir', value: 'save', className: 'cld-btn--primary' },
                { label: 'Descartar cambios', value: 'discard', className: 'cld-btn--outline' },
                { label: 'Seguir aquí', value: 'cancel' }
            ]
        }).then(function (choice) {
            if (choice === 'cancel') {
                return false;
            }

            if (choice === 'discard') {
                if (section === 'heroes') {
                    resetHeroDraft();
                } else {
                    state.events.draft = null;
                    state.events.savedJson = '';
                }

                return true;
            }

            return (section === 'heroes' ? saveHeroes() : saveEvent());
        });
    }

    window.addEventListener('beforeunload', function (event) {
        if (isDirty('heroes') || isDirty('events')) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    function sectionFromHash() {
        var hash = window.location.hash.replace('#', '');

        if (hash === 'eventos') {
            return 'events';
        }

        if (hash === 'carteles') {
            return 'heroes';
        }

        return 'heroes';
    }

    function goTo(section) {
        if (section === state.section) {
            return;
        }

        guard(state.section).then(function (ok) {
            if (!ok) {
                syncHash();
                return;
            }

            state.section = section;
            syncHash();
            render();
            main.focus({ preventScroll: true });
        });
    }

    function syncHash() {
        var hash = state.section === 'events' ? '#eventos' : '#carteles';

        if (window.location.hash !== hash) {
            history.replaceState(null, '', hash);
        }

        root.querySelectorAll('[data-cld-nav]').forEach(function (link) {
            if (link.getAttribute('data-cld-nav') === state.section) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }

    function decorateNav() {
        root.querySelectorAll('[data-cld-nav]').forEach(function (link) {
            var section = link.getAttribute('data-cld-nav');

            if (!link.querySelector('.cld-icon')) {
                link.insertBefore(icon(section === 'heroes' ? 'posters' : 'calendar'), link.firstChild);
                link.appendChild(h('span', { class: 'cld-panel__nav-count', 'data-cld-count': section }));
            }

            link.addEventListener('click', function (event) {
                event.preventDefault();
                goTo(section);
            });
        });

        window.addEventListener('hashchange', function () {
            goTo(sectionFromHash());
        });
    }

    function updateCounts() {
        if (!state.data) {
            return;
        }

        var carousel = currentCarousel();
        var first = (state.data.heroes || [])[0];
        var heroCount = carousel ? state.heroes.draft.length : (first ? first.slides.length : 0);
        var counts = { heroes: heroCount, events: state.data.events.length };

        root.querySelectorAll('[data-cld-count]').forEach(function (el) {
            var n = counts[el.getAttribute('data-cld-count')];
            el.textContent = n ? String(n) : '';
        });
    }

    /* ==============================
     *  Render común
     * ============================== */

    function render() {
        main.textContent = '';

        if (state.loadError) {
            main.appendChild(h('div', { class: 'cld-list' },
                h('h1', { class: 'cld-title', text: 'No se ha podido abrir el panel' }),
                h('div', { class: 'cld-banner cld-banner--error', role: 'alert' }, icon('alert'), h('span', { text: state.loadError })),
                h('div', {}, h('button', { type: 'button', class: 'cld-btn cld-btn--primary', onClick: load }, 'Volver a intentarlo'))
            ));
            return;
        }

        if (!state.data) {
            main.appendChild(h('p', { class: 'cld-panel__loading', role: 'status', text: 'Cargando el panel…' }));
            return;
        }

        if (state.section === 'events') {
            renderEvents();
        } else {
            renderHeroes();
        }

        updateCounts();
    }

    function saveBar(options) {
        var status;

        if (options.error) {
            status = h('span', { class: 'cld-bar__status cld-bar__status--error', role: 'alert', text: options.error });
        } else if (options.dirty) {
            status = h('span', { class: 'cld-bar__status cld-bar__status--dirty', text: options.dirtyText || 'Cambios sin guardar' });
        } else {
            status = h('span', { class: 'cld-bar__status', text: options.cleanText || 'Todo guardado' });
        }

        return h('div', { class: 'cld-bar', 'data-cld-bar': '' },
            status,
            options.extra || null,
            options.dirty && options.onDiscard
                ? h('button', { type: 'button', class: 'cld-btn cld-btn--outline', onClick: options.onDiscard }, 'Descartar')
                : null,
            h('button', {
                type: 'button',
                class: 'cld-btn cld-btn--primary',
                'aria-busy': options.saving ? 'true' : null,
                'aria-disabled': options.saving || (!options.dirty && !options.alwaysSave) ? 'true' : null,
                onClick: function () {
                    if (!options.saving && (options.dirty || options.alwaysSave)) {
                        options.onSave();
                    }
                }
            }, options.saving ? 'Guardando…' : (options.saveLabel || 'Guardar cambios'))
        );
    }

    function field(options, control) {
        var errorId = options.id + '-error';
        var helpId = options.help ? options.id + '-help' : null;
        var describedBy = [helpId, options.error ? errorId : null].filter(Boolean).join(' ') || null;

        control.id = options.id;

        if (describedBy) {
            control.setAttribute('aria-describedby', describedBy);
        }

        if (options.error) {
            control.setAttribute('aria-invalid', 'true');
        }

        return h('div', { class: 'cld-field', 'data-cld-field': options.key || options.id },
            h('label', { class: 'cld-label', for: options.id },
                options.label,
                options.optional ? h('small', { text: ' (opcional)' }) : null
            ),
            control,
            options.help ? h('p', { class: 'cld-help', id: helpId, text: options.help }) : null,
            options.error ? h('p', { class: 'cld-error', id: errorId }, icon('alert'), h('span', { text: options.error })) : null
        );
    }

    function backButton(onBack) {
        return h('button', { type: 'button', class: 'cld-btn cld-btn--outline cld-btn--small cld-back', onClick: onBack },
            icon('back'), 'Volver a la lista');
    }

    /* ==============================
     *  Carteles de portada
     * ============================== */

    function currentCarousel() {
        var list = (state.data && state.data.heroes) || [];

        for (var i = 0; i < list.length; i++) {
            if (list[i].key === state.heroes.key) {
                return list[i];
            }
        }

        return null;
    }

    function useCarousel(carousel) {
        state.heroes.key = carousel ? carousel.key : '';
        state.heroes.draft = carousel ? carousel.slides.map(function (slide) {
            return Object.assign({ uid: slide.id || 'n' + (++newIds) }, clone(slide));
        }) : [];
        state.heroes.savedJson = JSON.stringify(heroPayload());
        state.heroes.savedOrder = state.heroes.draft.map(function (slide) { return slide.uid; });
        state.heroes.moved = {};
        state.heroes.errors = {};
        state.heroes.selected = Math.min(state.heroes.selected, Math.max(0, state.heroes.draft.length - 1));
    }

    function resetHeroDraft() {
        useCarousel(currentCarousel());
    }

    function heroPayload() {
        return state.heroes.draft.map(function (slide) {
            return {
                id: slide.id || '',
                title: slide.title || '',
                desktop: slide.desktop ? { id: slide.desktop.id } : null,
                mobile: slide.mobile ? { id: slide.mobile.id } : null,
                poster: slide.poster ? { id: slide.poster.id } : null,
                link: slide.link || ''
            };
        });
    }

    function slideHasVideo(slide) {
        return (slide.desktop && slide.desktop.type === 'video') || (slide.mobile && slide.mobile.type === 'video');
    }

    function slideMeta(slide) {
        var kind = !slide.desktop ? 'Falta la imagen' : (slide.desktop.type === 'video' ? 'Vídeo' : 'Imagen');

        return kind + ' · ' + (slide.link ? 'con enlace de compra' : 'sin enlace');
    }

    function renderHeroes() {
        var heroes = state.heroes;
        var carousels = state.data.heroes || [];

        if (!heroes.key && carousels.length) {
            useCarousel(carousels[0]);
        }

        var work = h('div', { class: 'cld-work', 'data-view': heroes.view });
        work.appendChild(renderHeroList(carousels));
        work.appendChild(renderHeroDetail());
        main.appendChild(work);
    }

    function renderHeroList(carousels) {
        var heroes = state.heroes;
        var carousel = currentCarousel();
        var list = h('section', { class: 'cld-list', 'aria-labelledby': 'cld-heroes-title' });

        list.appendChild(h('div', { class: 'cld-list__head' },
            h('h1', { class: 'cld-title', id: 'cld-heroes-title', text: 'Carteles de portada' }),
            h('p', { class: 'cld-lead', text: 'Salen en la portada en este orden. Pulsa un cartel para cambiarlo.' })
        ));

        if (!state.data.heroesAvailable) {
            list.appendChild(emptyBlock('Elementor no está activo', 'Los carteles de portada se guardan en una página de Elementor. Pide a quien administra la web que lo active.'));
            return list;
        }

        if (!carousels.length) {
            list.appendChild(emptyBlock('Todavía no hay carrusel de carteles', 'Cuando se añada el carrusel "Cloudari Hero Carrusel" a una página, sus carteles aparecerán aquí.'));
            return list;
        }

        if (carousels.length > 1) {
            var select = h('select', {
                class: 'cld-select',
                onChange: function (event) {
                    var target = event.target.value;
                    event.target.value = heroes.key;

                    guard('heroes').then(function (ok) {
                        if (!ok) {
                            return;
                        }

                        heroes.selected = 0;
                        useCarousel(carousels.filter(function (c) { return c.key === target; })[0]);
                        render();
                    });
                }
            }, carousels.map(function (c) {
                return h('option', { value: c.key, selected: c.key === heroes.key ? true : null }, c.page_title);
            }));

            list.appendChild(field({ id: 'cld-carousel', label: 'Página' }, select));
        }

        list.appendChild(h('div', { class: 'cld-list__tools' },
            h('button', { type: 'button', class: 'cld-btn cld-btn--primary', onClick: addSlide }, icon('plus'), 'Añadir cartel'),
            carousel && carousel.page_url
                ? h('a', { class: 'cld-link', href: carousel.page_url, target: '_blank', rel: 'noopener' }, 'Ver la portada')
                : null
        ));

        if (!heroes.draft.length) {
            list.appendChild(emptyBlock('No hay carteles', 'Añade el primero con el botón de arriba.'));
            return list;
        }

        var items = h('ol', { class: 'cld-items', 'aria-label': 'Orden de los carteles' });

        heroes.draft.forEach(function (slide, index) {
            items.appendChild(renderHeroItem(slide, index));
        });

        list.appendChild(items);

        return list;
    }

    function renderHeroItem(slide, index) {
        var heroes = state.heroes;
        var total = heroes.draft.length;
        var name = slide.title || 'Cartel sin nombre';
        // Marcado mientras siga fuera de su sitio: si vuelve a donde estaba, deja de estarlo.
        var moved = !!heroes.moved[slide.uid] && (slide.isNew || heroes.savedOrder.indexOf(slide.uid) !== index);
        var hasError = !!heroes.errors['slide-' + index];
        var thumb = h('span', { class: 'cld-thumb' }, mediaPreview(slide.desktop, slide.poster) || icon('image'));

        if (slide.desktop && slide.desktop.type === 'video') {
            thumb.appendChild(h('span', { class: 'cld-thumb__badge' }, icon('video'), 'Vídeo'));
        }

        var item = h('li', {
            class: 'cld-item' + (moved ? ' cld-item--moved' : ''),
            'aria-current': index === heroes.selected ? 'true' : null,
            'data-uid': slide.uid
        },
            h('button', {
                type: 'button',
                class: 'cld-item__select',
                'aria-label': 'Cartel ' + (index + 1) + ': ' + name + '. Cambiar',
                onClick: function () { selectSlide(index); }
            }),
            h('span', { class: 'cld-item__num', 'aria-hidden': 'true', text: String(index + 1) }),
            thumb,
            h('span', { class: 'cld-item__body' },
                h('span', { class: 'cld-item__title', 'data-cld-slide-title': slide.uid, text: name }),
                h('span', { class: 'cld-item__meta', text: slideMeta(slide) }),
                moved ? h('span', { class: 'cld-item__flag cld-item__moved', text: 'Movido, sin guardar' }) : null,
                hasError ? h('span', { class: 'cld-item__flag cld-item__flag--error', text: 'Falta la imagen para ordenador' }) : null
            ),
            h('span', { class: 'cld-item__order' },
                h('button', {
                    type: 'button',
                    class: 'cld-btn cld-btn--icon',
                    'aria-label': 'Subir el cartel ' + (index + 1) + ' (' + name + ')',
                    'aria-disabled': index === 0 ? 'true' : null,
                    'data-move': 'up',
                    onClick: function () { moveSlide(index, -1); }
                }, icon('up')),
                h('button', {
                    type: 'button',
                    class: 'cld-btn cld-btn--icon',
                    'aria-label': 'Bajar el cartel ' + (index + 1) + ' (' + name + ')',
                    'aria-disabled': index === total - 1 ? 'true' : null,
                    'data-move': 'down',
                    onClick: function () { moveSlide(index, 1); }
                }, icon('down'))
            )
        );

        return item;
    }

    function selectSlide(index) {
        state.heroes.selected = index;
        state.heroes.view = 'detail';
        render();

        var title = main.querySelector('.cld-detail__title');

        if (title) {
            title.focus({ preventScroll: true });
        }
    }

    /**
     * Firma del panel: el cartel se desplaza a su nuevo sitio (no desaparece y
     * reaparece) y se queda marcado en amarillo hasta que se guarda.
     */
    function moveSlide(index, delta) {
        var heroes = state.heroes;
        var target = index + delta;

        if (target < 0 || target >= heroes.draft.length) {
            return;
        }

        var slide = heroes.draft[index];
        var before = main.querySelector('[data-uid="' + slide.uid + '"]');
        var oldTop = before ? before.getBoundingClientRect().top : 0;

        heroes.draft.splice(index, 1);
        heroes.draft.splice(target, 0, slide);
        heroes.moved[slide.uid] = true;

        if (heroes.selected === index) {
            heroes.selected = target;
        } else if (heroes.selected === target) {
            heroes.selected = index;
        }

        render();

        var after = main.querySelector('[data-uid="' + slide.uid + '"]');

        if (after) {
            var shift = oldTop - after.getBoundingClientRect().top;
            after.style.setProperty('--cld-from', shift + 'px');
            after.classList.add('cld-item--arrived');
            after.addEventListener('animationend', function () {
                after.classList.remove('cld-item--arrived');
            }, { once: true });

            var sameButton = after.querySelector('[data-move="' + (delta < 0 ? 'up' : 'down') + '"]');
            var focusTarget = sameButton && sameButton.getAttribute('aria-disabled') !== 'true'
                ? sameButton
                : after.querySelector('[data-move="' + (delta < 0 ? 'down' : 'up') + '"]');

            if (focusTarget) {
                focusTarget.focus();
            }
        }

        announce((slide.title || 'El cartel') + ' pasa a la posición ' + (target + 1) + ' de ' + heroes.draft.length + '. Guarda para que se vea así en la web.');
    }

    function addSlide() {
        var heroes = state.heroes;

        heroes.draft.push({ uid: 'n' + (++newIds), id: '', title: '', desktop: null, mobile: null, poster: null, link: '', isNew: true });
        heroes.moved[heroes.draft[heroes.draft.length - 1].uid] = true;
        heroes.selected = heroes.draft.length - 1;
        heroes.view = 'detail';
        render();

        var input = main.querySelector('#cld-slide-title');

        if (input) {
            input.focus();
        }

        announce('Cartel nuevo añadido al final. Elige su imagen y guarda.');
    }

    function removeSlide(index) {
        var heroes = state.heroes;
        var slide = heroes.draft[index];

        confirmDialog({
            title: '¿Quitar «' + (slide.title || 'este cartel') + '» de la portada?',
            text: 'Desaparecerá de la web cuando pulses "Guardar cambios". Las imágenes siguen en la Mediateca.',
            actions: [
                { label: 'Quitar cartel', value: 'remove', className: 'cld-btn--danger-solid' },
                { label: 'Cancelar', value: 'cancel' }
            ]
        }).then(function (choice) {
            if (choice !== 'remove') {
                return;
            }

            heroes.draft.splice(index, 1);
            heroes.errors = {};
            heroes.selected = Math.max(0, Math.min(index, heroes.draft.length - 1));
            heroes.view = 'list';
            render();
            announce('Cartel quitado. Guarda para aplicarlo en la web.');
        });
    }

    function renderHeroDetail() {
        var heroes = state.heroes;
        var detail = h('section', { class: 'cld-detail', 'aria-label': 'Ficha del cartel' });
        var carousel = currentCarousel();
        var slide = heroes.draft[heroes.selected];

        if (!carousel || !slide) {
            detail.appendChild(h('div', { class: 'cld-detail__empty' },
                h('p', { text: carousel ? 'Añade un cartel para empezar.' : 'Aquí aparecerá la ficha del cartel que elijas.' })
            ));

            if (carousel && isDirty('heroes')) {
                detail.appendChild(heroBar());
            }

            return detail;
        }

        var index = heroes.selected;
        var scroll = h('div', { class: 'cld-detail__scroll' });

        scroll.appendChild(backButton(function () {
            heroes.view = 'list';
            render();
        }));

        scroll.appendChild(h('div', { class: 'cld-detail__head' },
            h('h2', { class: 'cld-detail__title', tabindex: '-1' },
                h('span', { class: 'cld-detail__num', text: 'Cartel ' + (index + 1) }),
                ' · ',
                h('span', { 'data-cld-detail-name': '', text: slide.title || 'Sin nombre' })
            ),
            cfg.canClassic && carousel.edit_url
                ? h('a', { class: 'cld-link', href: carousel.edit_url }, 'Abrir la página en Elementor')
                : null
        ));

        var form = h('div', { class: 'cld-form' });

        form.appendChild(h('div', { class: 'cld-section' },
            field({
                id: 'cld-slide-title',
                label: 'Nombre del espectáculo',
                help: 'Lo leen los lectores de pantalla y aparece en el botón de compra.'
            }, h('input', {
                class: 'cld-input',
                type: 'text',
                value: slide.title || '',
                autocomplete: 'off',
                onInput: function (event) {
                    slide.title = event.target.value;
                    refreshHeroChrome(slide);
                }
            }))
        ));

        var desktopError = heroes.errors['slide-' + index];
        var media = h('div', { class: 'cld-section' },
            h('h3', { class: 'cld-section__title', text: 'Imagen o vídeo' }),
            h('p', { class: 'cld-section__hint', text: 'Para ordenador, mejor apaisada (1920 × 800). Para móvil y tablet, una vertical o cuadrada se ve más grande.' }),
            h('div', { class: 'cld-media-pair' },
                mediaSlot({
                    id: 'cld-slide-desktop',
                    label: 'Para ordenador',
                    media: slide.desktop,
                    poster: slide.poster,
                    types: ['image', 'video'],
                    error: desktopError,
                    onPick: function (picked) { slide.desktop = picked; },
                    onRemove: null
                }),
                mediaSlot({
                    id: 'cld-slide-mobile',
                    label: 'Para móvil y tablet',
                    optional: true,
                    portrait: true,
                    media: slide.mobile,
                    inherit: slide.mobile ? null : slide.desktop,
                    inheritNote: 'Se usa la de ordenador',
                    poster: slide.poster,
                    types: ['image', 'video'],
                    onPick: function (picked) { slide.mobile = picked; },
                    onRemove: function () { slide.mobile = null; }
                })
            )
        );

        if (slideHasVideo(slide)) {
            media.appendChild(mediaSlot({
                id: 'cld-slide-poster',
                label: 'Imagen mientras carga el vídeo',
                optional: true,
                square: true,
                help: 'Se ve un instante antes de que arranque el vídeo, y en su lugar a quien tiene desactivadas las animaciones.',
                media: slide.poster,
                types: ['image'],
                onPick: function (picked) { slide.poster = picked; },
                onRemove: function () { slide.poster = null; }
            }));
        }

        form.appendChild(media);

        form.appendChild(h('div', { class: 'cld-section' },
            h('h3', { class: 'cld-section__title', text: 'Al pulsar el cartel' }),
            field({
                id: 'cld-slide-link',
                label: 'Enlace para comprar entradas',
                optional: true,
                help: 'Pega la dirección de la página de compra. Si lo dejas vacío, el cartel solo informa y no lleva a ningún sitio.'
            }, h('input', {
                class: 'cld-input',
                type: 'url',
                inputmode: 'url',
                placeholder: 'https://',
                value: slide.link || '',
                onInput: function (event) {
                    slide.link = event.target.value.trim();
                    refreshHeroChrome(slide);
                },
                onBlur: function (event) {
                    slide.link = normalizeUrl(event.target.value);
                    event.target.value = slide.link;
                    refreshHeroChrome(slide);
                }
            }))
        ));

        form.appendChild(h('div', { class: 'cld-section' },
            h('h3', { class: 'cld-section__title', text: 'Quitar de la portada' }),
            h('p', { class: 'cld-section__hint', text: 'El cartel deja de verse en la web al guardar. Puedes volver a añadirlo cuando quieras.' }),
            h('div', {},
                h('button', { type: 'button', class: 'cld-btn cld-btn--danger', onClick: function () { removeSlide(index); } },
                    icon('trash'), 'Quitar este cartel')
            )
        ));

        scroll.appendChild(form);
        detail.appendChild(scroll);
        detail.appendChild(heroBar());

        return detail;
    }

    function heroBar() {
        var heroes = state.heroes;

        return saveBar({
            dirty: isDirty('heroes'),
            saving: heroes.saving,
            error: heroes.errors._general || '',
            dirtyText: 'Cambios sin guardar: la web sigue como antes',
            cleanText: 'La portada está al día',
            onSave: function () { saveHeroes(); },
            onDiscard: function () {
                confirmDialog({
                    title: '¿Descartar los cambios?',
                    text: 'Los carteles vuelven a como están ahora en la web.',
                    actions: [
                        { label: 'Descartar cambios', value: 'discard', className: 'cld-btn--danger-solid' },
                        { label: 'Seguir editando', value: 'cancel' }
                    ]
                }).then(function (choice) {
                    if (choice === 'discard') {
                        resetHeroDraft();
                        render();
                        announce('Cambios descartados.');
                    }
                });
            }
        });
    }

    /** Actualiza lo que depende del texto sin repintar el formulario (no se pierde el foco). */
    function refreshHeroChrome(slide) {
        var title = main.querySelector('[data-cld-slide-title="' + slide.uid + '"]');
        var name = main.querySelector('[data-cld-detail-name]');

        if (title) {
            title.textContent = slide.title || 'Cartel sin nombre';
        }

        if (name) {
            name.textContent = slide.title || 'Sin nombre';
        }

        var meta = title ? title.parentNode.querySelector('.cld-item__meta') : null;

        if (meta) {
            meta.textContent = slideMeta(slide);
        }

        replaceBar(heroBar());
    }

    function replaceBar(bar) {
        var old = main.querySelector('[data-cld-bar]');

        if (old) {
            old.replaceWith(bar);
        }
    }

    function mediaSlot(options) {
        var media = options.media;
        var shown = media || options.inherit || null;
        var frameClass = 'cld-media__frame'
            + (shown ? ' cld-media__frame--filled' : '')
            + (!media && options.inherit ? ' cld-media__frame--inherit' : '');
        var frame = h('div', { class: frameClass, id: options.id + '-frame' });

        if (shown) {
            frame.appendChild(mediaPreview(shown, options.poster));

            if (!media && options.inherit) {
                frame.appendChild(h('span', { class: 'cld-media__note', text: options.inheritNote }));
            }
        } else {
            frame.appendChild(h('span', { class: 'cld-media__empty' }, icon('image'), h('span', { text: 'Todavía no hay nada elegido' })));
        }

        var pick = function () {
            pickMedia(options.label, options.types).then(function (picked) {
                if (!picked) {
                    return;
                }

                options.onPick(picked);
                state.heroes.errors = {};
                state.events.errors = {};
                rerenderDetailKeepingScroll();

                var button = main.querySelector('#' + options.id + '-pick');

                if (button) {
                    button.focus();
                }

                announce('Archivo elegido: ' + (picked.name || 'sin nombre') + '. Recuerda guardar.');
            });
        };

        var errorId = options.id + '-error';

        return h('div', {
            class: 'cld-media' + (options.portrait ? ' cld-media--portrait' : '') + (options.square ? ' cld-media--square' : ''),
            role: 'group',
            'aria-labelledby': options.id + '-label'
        },
            h('span', { class: 'cld-label', id: options.id + '-label' },
                options.label,
                options.optional ? h('small', { text: ' (opcional)' }) : null
            ),
            frame,
            media && media.name ? h('span', { class: 'cld-media__name', text: media.name }) : null,
            options.help ? h('p', { class: 'cld-help', text: options.help }) : null,
            options.error ? h('p', { class: 'cld-error', id: errorId }, icon('alert'), h('span', { text: options.error })) : null,
            h('span', { class: 'cld-media__actions' },
                h('button', {
                    type: 'button',
                    class: 'cld-btn cld-btn--outline cld-btn--small',
                    id: options.id + '-pick',
                    'aria-describedby': options.error ? errorId : null,
                    onClick: pick
                }, icon('image'), media ? 'Cambiar' : 'Elegir de la Mediateca'),
                media && options.onRemove
                    ? h('button', {
                        type: 'button',
                        class: 'cld-btn cld-btn--small',
                        onClick: function () {
                            options.onRemove();
                            rerenderDetailKeepingScroll();
                            announce('Quitado. Recuerda guardar.');
                        }
                    }, 'Quitar')
                    : null
            )
        );
    }

    function rerenderDetailKeepingScroll() {
        var y = window.scrollY;
        render();
        window.scrollTo(0, y);
    }

    function saveHeroes() {
        var heroes = state.heroes;
        var carousel = currentCarousel();

        if (!carousel || heroes.saving) {
            return Promise.resolve(false);
        }

        var missing = {};

        heroes.draft.forEach(function (slide, index) {
            if (!slide.desktop) {
                missing['slide-' + index] = 'Falta la imagen o el vídeo para ordenador.';
            }
        });

        if (Object.keys(missing).length) {
            var first = parseInt(Object.keys(missing)[0].replace('slide-', ''), 10);
            heroes.errors = missing;
            heroes.errors._general = 'Hay un cartel sin imagen para ordenador.';
            heroes.selected = first;
            heroes.view = 'detail';
            render();

            var pick = main.querySelector('#cld-slide-desktop-pick');

            if (pick) {
                pick.focus();
            }

            return Promise.resolve(false);
        }

        heroes.saving = true;
        heroes.errors = {};
        replaceBar(heroBar());

        heroes.draft.forEach(function (slide) {
            slide.link = normalizeUrl(slide.link);
        });

        return api('/heroes/' + carousel.post_id + '/' + carousel.element_id, {
            method: 'PUT',
            data: { slides: heroPayload() }
        }).then(function (updated) {
            var list = state.data.heroes;

            for (var i = 0; i < list.length; i++) {
                if (list[i].key === updated.key) {
                    list[i] = updated;
                }
            }

            heroes.saving = false;
            useCarousel(updated);
            render();
            toast('Guardado. La portada ya se ve así.');

            return true;
        }).catch(function (error) {
            heroes.saving = false;
            heroes.errors = errorFields(error);
            heroes.errors._general = errorMessage(error);
            render();

            return false;
        });
    }

    /* ==============================
     *  Eventos manuales
     * ============================== */

    function eventDraftFrom(event) {
        return {
            id: event.id,
            title: event.title || '',
            visible: event.visible,
            mode: event.mode || 'sessions',
            sessions: event.sessions && event.sessions.length ? clone(event.sessions) : [{ fecha: '', hora: '', hora_fin: '' }],
            range_start: event.range_start || '',
            range_end: event.range_end || '',
            rules: Object.assign({}, DEFAULT_RULES, event.rules || {}),
            exceptions: clone(event.exceptions || []),
            url: event.url || '',
            cta_label: event.cta_label || '',
            image_id: event.image_id || 0,
            image_url: event.image_url || '',
            category_id: event.category_id || 0,
            venue: event.venue || ''
        };
    }

    function blankEvent() {
        return eventDraftFrom({ id: 0, visible: true, mode: 'sessions' });
    }

    function openEvent(id) {
        var events = state.events;

        guard('events').then(function (ok) {
            if (!ok) {
                return;
            }

            var event = id === 'new' ? null : findEvent(id);

            events.selected = id;
            events.errors = {};
            events.draft = event ? eventDraftFrom(event) : blankEvent();
            events.savedJson = JSON.stringify(events.draft);
            events.view = 'detail';
            render();

            var focus = id === 'new' ? main.querySelector('#cld-ev-title') : main.querySelector('.cld-detail__title');

            if (focus) {
                focus.focus({ preventScroll: id !== 'new' });
            }
        });
    }

    function findEvent(id) {
        var list = state.data.events;

        for (var i = 0; i < list.length; i++) {
            if (list[i].id === id) {
                return list[i];
            }
        }

        return null;
    }

    function categoryOf(id) {
        var list = state.data.categories || [];

        for (var i = 0; i < list.length; i++) {
            if (list[i].id === id) {
                return list[i];
            }
        }

        return null;
    }

    function eventGroup(event) {
        if (event.mode === 'permanent') {
            return 'always';
        }

        if (!event.next_date) {
            return 'undated';
        }

        return event.next_date < today() ? 'past' : 'upcoming';
    }

    function renderEvents() {
        var work = h('div', { class: 'cld-work', 'data-view': state.events.view });
        work.appendChild(renderEventList());
        work.appendChild(renderEventDetail());
        main.appendChild(work);
    }

    function renderEventList() {
        var events = state.events;
        var list = h('section', { class: 'cld-list', 'aria-labelledby': 'cld-events-title' });

        list.appendChild(h('div', { class: 'cld-list__head' },
            h('h1', { class: 'cld-title', id: 'cld-events-title', text: 'Eventos manuales' }),
            h('p', { class: 'cld-lead', text: 'Eventos que no vienen de la taquilla y quieres mostrar en la cartelera y el calendario.' })
        ));

        list.appendChild(h('div', { class: 'cld-list__tools' },
            h('button', { type: 'button', class: 'cld-btn cld-btn--primary', onClick: function () { openEvent('new'); } },
                icon('plus'), 'Añadir evento')
        ));

        var all = state.data.events;

        if (all.length > 5) {
            list.appendChild(field({ id: 'cld-ev-search', label: 'Buscar por nombre' }, h('input', {
                class: 'cld-input',
                type: 'search',
                value: events.query,
                autocomplete: 'off',
                onInput: function (event) {
                    events.query = event.target.value;
                    var holder = main.querySelector('[data-cld-event-items]');

                    if (holder) {
                        holder.replaceWith(eventItems());
                    }
                }
            })));
        }

        if (!all.length) {
            list.appendChild(emptyBlock('Todavía no hay eventos manuales', 'Pulsa "Añadir evento" para crear el primero. Aparecerá en la cartelera junto a los de la taquilla.'));
            return list;
        }

        list.appendChild(eventItems());

        return list;
    }

    function eventItems() {
        var events = state.events;
        var query = events.query.trim().toLowerCase();
        var holder = h('div', { 'data-cld-event-items': '' });
        var groups = { upcoming: [], always: [], undated: [], past: [] };
        var labels = { upcoming: 'Próximos', always: 'Siempre visibles', undated: 'Sin fechas todavía', past: 'Ya pasados' };

        state.data.events.forEach(function (event) {
            if (query && event.title.toLowerCase().indexOf(query) === -1) {
                return;
            }

            groups[eventGroup(event)].push(event);
        });

        groups.upcoming.sort(function (a, b) { return a.next_date < b.next_date ? -1 : 1; });
        groups.past.sort(function (a, b) { return a.next_date > b.next_date ? -1 : 1; });

        var any = false;

        ['upcoming', 'always', 'undated', 'past'].forEach(function (key) {
            if (!groups[key].length) {
                return;
            }

            any = true;
            var headingId = 'cld-group-' + key;
            holder.appendChild(h('h2', { class: 'cld-group-label', id: headingId, text: labels[key] + ' (' + groups[key].length + ')' }));
            holder.appendChild(h('ul', { class: 'cld-items', 'aria-labelledby': headingId }, groups[key].map(renderEventItem)));
        });

        if (!any) {
            holder.appendChild(h('p', { class: 'cld-help', role: 'status', text: 'Ningún evento se llama así.' }));
        }

        return holder;
    }

    function renderEventItem(event) {
        var category = categoryOf(event.category_id);
        var thumb;

        if (event.image_url) {
            thumb = h('span', { class: 'cld-thumb' }, h('img', { src: event.image_url, alt: '', loading: 'lazy', decoding: 'async' }));
        } else {
            thumb = h('span', {
                class: 'cld-thumb cld-thumb--swatch',
                style: 'background:' + (category && /^#[0-9a-f]{3,8}$/i.test(category.color) ? category.color : '#475569'),
                'aria-hidden': 'true',
                text: (event.title || '?').trim().charAt(0).toUpperCase()
            });
        }

        var flag = event.visible
            ? (event.status === 'pending'
                ? h('span', { class: 'cld-item__flag cld-item__flag--warn', text: 'Pendiente de revisión' })
                : h('span', { class: 'cld-item__flag cld-item__flag--on', text: 'Se ve en la web' }))
            : h('span', { class: 'cld-item__flag cld-item__flag--off', text: 'Oculto' });

        return h('li', {
            class: 'cld-item cld-item--events',
            'aria-current': state.events.selected === event.id ? 'true' : null
        },
            h('button', {
                type: 'button',
                class: 'cld-item__select',
                'aria-label': event.title + '. ' + event.when + '. Abrir',
                onClick: function () { openEvent(event.id); }
            }),
            thumb,
            h('span', { class: 'cld-item__body' },
                h('span', { class: 'cld-item__title', text: event.title || 'Sin nombre' }),
                h('span', { class: 'cld-item__meta', text: event.when + (category ? ' · ' + category.name : '') }),
                flag
            )
        );
    }

    function renderEventDetail() {
        var events = state.events;
        var detail = h('section', { class: 'cld-detail', 'aria-label': 'Ficha del evento' });
        var draft = events.draft;

        if (!draft) {
            detail.appendChild(h('div', { class: 'cld-detail__empty' },
                h('p', { text: 'Elige un evento de la lista para cambiarlo, o pulsa "Añadir evento".' })
            ));

            return detail;
        }

        var errors = events.errors;
        var isNew = !draft.id;
        var scroll = h('div', { class: 'cld-detail__scroll' });

        scroll.appendChild(backButton(function () {
            guard('events').then(function (ok) {
                if (ok) {
                    events.view = 'list';
                    render();
                }
            });
        }));

        scroll.appendChild(h('div', { class: 'cld-detail__head' },
            h('h2', { class: 'cld-detail__title', tabindex: '-1' },
                isNew ? h('span', { class: 'cld-detail__num', text: 'Evento nuevo' }) : h('span', { 'data-cld-detail-name': '', text: draft.title || 'Sin nombre' })
            )
        ));

        var form = h('div', { class: 'cld-form' });

        if (errors._general) {
            form.appendChild(h('div', { class: 'cld-banner cld-banner--error', role: 'alert' }, icon('alert'), h('span', { text: errors._general })));
        }

        // 1. Qué es
        form.appendChild(h('div', { class: 'cld-section' },
            h('h3', { class: 'cld-section__title', text: 'El evento' }),
            field({ id: 'cld-ev-title', key: 'title', label: 'Nombre del evento', error: errors.title }, h('input', {
                class: 'cld-input',
                type: 'text',
                value: draft.title,
                autocomplete: 'off',
                required: true,
                onInput: function (event) {
                    draft.title = event.target.value;
                    refreshEventChrome();
                }
            })),
            eventImageSlot(draft),
            h('div', { class: 'cld-row' },
                field({ id: 'cld-ev-category', label: 'Categoría', optional: true, help: 'Da el color de la etiqueta en la cartelera.' },
                    h('select', {
                        class: 'cld-select',
                        onChange: function (event) {
                            draft.category_id = parseInt(event.target.value, 10) || 0;
                            refreshEventChrome();
                        }
                    },
                        h('option', { value: '0', text: 'Sin categoría' }),
                        (state.data.categories || []).map(function (cat) {
                            return h('option', { value: String(cat.id), selected: cat.id === draft.category_id ? true : null, text: cat.name });
                        })
                    )
                ),
                field({ id: 'cld-ev-venue', label: 'Espacio', optional: true, help: 'Dónde se celebra, si no es la sala principal.' },
                    h('select', {
                        class: 'cld-select',
                        onChange: function (event) {
                            draft.venue = event.target.value;
                            refreshEventChrome();
                        }
                    },
                        h('option', { value: '', text: 'La sala principal' }),
                        venueOptions(draft.venue).map(function (venue) {
                            return h('option', { value: venue, selected: venue === draft.venue ? true : null, text: venue });
                        })
                    )
                )
            )
        ));

        // 2. Cuándo
        var when = h('div', { class: 'cld-section' },
            h('h3', { class: 'cld-section__title', id: 'cld-ev-mode-title', text: '¿Cuándo es?' })
        );

        when.appendChild(h('div', { class: 'cld-choice', role: 'radiogroup', 'aria-labelledby': 'cld-ev-mode-title' },
            MODES.map(function (mode) {
                return h('label', { class: 'cld-choice__opt' },
                    h('input', {
                        type: 'radio',
                        name: 'cld-ev-mode',
                        value: mode.value,
                        checked: draft.mode === mode.value,
                        onChange: function () {
                            draft.mode = mode.value;
                            events.errors = {};
                            rerenderDetailKeepingScroll();
                            var radio = main.querySelector('input[name="cld-ev-mode"][value="' + mode.value + '"]');

                            if (radio) {
                                radio.focus();
                            }
                        }
                    }),
                    h('span', { class: 'cld-choice__name', text: mode.name }),
                    h('span', { class: 'cld-choice__desc', text: mode.desc })
                );
            })
        ));

        if (draft.mode === 'sessions') {
            when.appendChild(sessionRows(draft, errors));
        } else if (draft.mode === 'range') {
            when.appendChild(rangeFields(draft, errors));
        } else {
            when.appendChild(h('p', { class: 'cld-banner' }, h('span', { text: 'Saldrá en la cartelera con la etiqueta "Información permanente". No ocupa días en el calendario.' })));
        }

        form.appendChild(when);

        // 3. Entradas
        form.appendChild(h('div', { class: 'cld-section' },
            h('h3', { class: 'cld-section__title', text: 'Entradas o más información' }),
            h('div', { class: 'cld-row' },
                field({
                    id: 'cld-ev-url',
                    key: 'url',
                    label: 'Enlace',
                    optional: true,
                    error: errors.url,
                    help: 'Donde se compran las entradas o se lee más.'
                }, h('input', {
                    class: 'cld-input',
                    type: 'url',
                    inputmode: 'url',
                    placeholder: 'https://',
                    value: draft.url,
                    onInput: function (event) {
                        draft.url = event.target.value.trim();
                        refreshEventChrome();
                    },
                    onBlur: function (event) {
                        draft.url = normalizeUrl(event.target.value);
                        event.target.value = draft.url;
                        refreshEventChrome();
                    }
                })),
                field({
                    id: 'cld-ev-cta',
                    label: 'Texto del botón',
                    optional: true,
                    help: 'Si lo dejas vacío pone "Entradas". Por ejemplo: Reservar, Gratis, Inscripción.'
                }, h('input', {
                    class: 'cld-input',
                    type: 'text',
                    placeholder: 'Entradas',
                    value: draft.cta_label,
                    onInput: function (event) {
                        draft.cta_label = event.target.value;
                        refreshEventChrome();
                    }
                }))
            )
        ));

        // 4. Visibilidad
        form.appendChild(h('div', { class: 'cld-section' },
            h('h3', { class: 'cld-section__title', text: 'En la web' }),
            h('label', { class: 'cld-switch' },
                h('input', {
                    type: 'checkbox',
                    role: 'switch',
                    id: 'cld-ev-visible',
                    checked: draft.visible,
                    onChange: function (event) {
                        draft.visible = event.target.checked;
                        refreshEventChrome();
                    }
                }),
                h('span', { class: 'cld-switch__text' },
                    h('span', { class: 'cld-label', text: 'Mostrar en la web' }),
                    h('span', { class: 'cld-help', text: 'Desactívalo para prepararlo sin que nadie lo vea todavía.' })
                )
            )
        ));

        if (!isNew) {
            form.appendChild(h('div', { class: 'cld-section' },
                h('h3', { class: 'cld-section__title', text: 'Quitar el evento' }),
                h('p', { class: 'cld-section__hint', text: 'Desaparece de la web y va a la papelera de WordPress, desde donde un administrador puede recuperarlo.' }),
                h('div', {},
                    h('button', { type: 'button', class: 'cld-btn cld-btn--danger', onClick: deleteEvent }, icon('trash'), 'Quitar este evento')
                ),
                cfg.canClassic
                    ? h('p', { class: 'cld-help' },
                        h('a', { class: 'cld-link', href: cfg.postEditUrl + '?post=' + draft.id + '&action=edit' }, 'Abrir en el editor de WordPress'))
                    : null
            ));
        }

        scroll.appendChild(form);
        detail.appendChild(scroll);
        detail.appendChild(eventBar());

        return detail;
    }

    function venueOptions(current) {
        var list = (state.data.venues || []).slice();

        if (current && list.indexOf(current) === -1) {
            list.push(current);
        }

        return list;
    }

    function eventImageSlot(draft) {
        var media = draft.image_id ? { id: draft.image_id, url: draft.image_url, thumb: draft.image_url, type: 'image', name: '' } : null;

        return mediaSlot({
            id: 'cld-ev-image',
            label: 'Imagen del evento',
            optional: true,
            square: true,
            help: 'Sale en la tarjeta de la cartelera.',
            media: media,
            types: ['image'],
            onPick: function (picked) {
                draft.image_id = picked.id;
                draft.image_url = picked.thumb || picked.url;
            },
            onRemove: function () {
                draft.image_id = 0;
                draft.image_url = '';
            }
        });
    }

    function sessionRows(draft, errors) {
        var wrap = h('div', { class: 'cld-field' });
        var list = h('ol', { class: 'cld-repeat', 'aria-label': 'Fechas del evento' });

        draft.sessions.forEach(function (row, index) {
            var n = index + 1;
            var rowError = errors['session-' + index];

            list.appendChild(h('li', { class: 'cld-repeat__row' },
                field({ id: 'cld-ses-date-' + index, label: 'Día (fecha ' + n + ')' }, h('input', {
                    class: 'cld-input',
                    type: 'date',
                    value: row.fecha,
                    onInput: function (event) { row.fecha = event.target.value; refreshEventChrome(); }
                })),
                field({ id: 'cld-ses-start-' + index, label: 'Empieza' }, h('input', {
                    class: 'cld-input',
                    type: 'time',
                    value: row.hora,
                    onInput: function (event) { row.hora = event.target.value; refreshEventChrome(); }
                })),
                field({ id: 'cld-ses-end-' + index, label: 'Termina', optional: true }, h('input', {
                    class: 'cld-input',
                    type: 'time',
                    value: row.hora_fin,
                    onInput: function (event) { row.hora_fin = event.target.value; refreshEventChrome(); }
                })),
                h('button', {
                    type: 'button',
                    class: 'cld-btn cld-btn--small',
                    'aria-label': 'Quitar la fecha ' + n,
                    onClick: function () {
                        if (draft.sessions.length === 1) {
                            draft.sessions[0] = { fecha: '', hora: '', hora_fin: '' };
                        } else {
                            draft.sessions.splice(index, 1);
                        }

                        rerenderDetailKeepingScroll();
                        var add = main.querySelector('#cld-ses-add');

                        if (add) {
                            add.focus();
                        }
                    }
                }, 'Quitar'),
                rowError ? h('p', { class: 'cld-error cld-field--error' }, icon('alert'), h('span', { text: rowError })) : null
            ));
        });

        wrap.appendChild(list);

        if (errors.sessions) {
            wrap.appendChild(h('p', { class: 'cld-error', id: 'cld-ses-error' }, icon('alert'), h('span', { text: errors.sessions })));
        }

        wrap.appendChild(h('div', {},
            h('button', {
                type: 'button',
                class: 'cld-btn cld-btn--outline',
                id: 'cld-ses-add',
                onClick: function () {
                    var last = draft.sessions[draft.sessions.length - 1] || {};
                    // La nueva fecha hereda la hora de la anterior: casi siempre coincide.
                    draft.sessions.push({ fecha: '', hora: last.hora || '', hora_fin: last.hora_fin || '' });
                    rerenderDetailKeepingScroll();
                    var input = main.querySelector('#cld-ses-date-' + (draft.sessions.length - 1));

                    if (input) {
                        input.focus();
                    }
                }
            }, icon('plus'), 'Añadir otra fecha')
        ));

        return wrap;
    }

    function timeInput(draft, key) {
        return h('input', {
            class: 'cld-input',
            type: 'time',
            value: draft.rules[key] || '',
            onInput: function (event) { draft.rules[key] = event.target.value; refreshEventChrome(); }
        });
    }

    function rangeFields(draft, errors) {
        var wrap = h('div', { class: 'cld-form cld-form--nested' });

        wrap.appendChild(h('div', { class: 'cld-row' },
            field({ id: 'cld-range-start', key: 'range_start', label: 'Primer día', error: errors.range_start }, h('input', {
                class: 'cld-input',
                type: 'date',
                value: draft.range_start,
                onInput: function (event) { draft.range_start = event.target.value; refreshEventChrome(); }
            })),
            field({ id: 'cld-range-end', key: 'range_end', label: 'Último día', error: errors.range_end }, h('input', {
                class: 'cld-input',
                type: 'date',
                value: draft.range_end,
                min: draft.range_start || null,
                onInput: function (event) { draft.range_end = event.target.value; refreshEventChrome(); }
            }))
        ));

        wrap.appendChild(h('div', { class: 'cld-field' },
            h('p', { class: 'cld-label', text: 'Horario' }),
            h('p', { class: 'cld-help', text: 'Se aplica a todos los días del periodo.' }),
            h('div', { class: 'cld-row' },
                field({ id: 'cld-wd-start', label: 'De lunes a viernes, abre' }, timeInput(draft, 'weekday_start')),
                field({ id: 'cld-wd-end', label: 'De lunes a viernes, cierra' }, timeInput(draft, 'weekday_end')),
                field({ id: 'cld-we-start', label: 'Sábado y domingo, abre' }, timeInput(draft, 'weekend_start')),
                field({ id: 'cld-we-end', label: 'Sábado y domingo, cierra' }, timeInput(draft, 'weekend_end'))
            ),
            errors.rules ? h('p', { class: 'cld-error' }, icon('alert'), h('span', { text: errors.rules })) : null
        ));

        var list = h('ol', { class: 'cld-repeat', 'aria-label': 'Días con horario especial' });

        draft.exceptions.forEach(function (row, index) {
            var n = index + 1;

            list.appendChild(h('li', { class: 'cld-repeat__row' },
                field({ id: 'cld-ex-date-' + index, label: 'Día especial ' + n }, h('input', {
                    class: 'cld-input',
                    type: 'date',
                    value: row.date,
                    onInput: function (event) { row.date = event.target.value; refreshEventChrome(); }
                })),
                field({ id: 'cld-ex-start-' + index, label: 'Abre' }, h('input', {
                    class: 'cld-input',
                    type: 'time',
                    value: row.start,
                    onInput: function (event) { row.start = event.target.value; refreshEventChrome(); }
                })),
                field({ id: 'cld-ex-end-' + index, label: 'Cierra' }, h('input', {
                    class: 'cld-input',
                    type: 'time',
                    value: row.end,
                    onInput: function (event) { row.end = event.target.value; refreshEventChrome(); }
                })),
                h('button', {
                    type: 'button',
                    class: 'cld-btn cld-btn--small',
                    'aria-label': 'Quitar el día especial ' + n,
                    onClick: function () {
                        draft.exceptions.splice(index, 1);
                        rerenderDetailKeepingScroll();
                        var add = main.querySelector('#cld-ex-add');

                        if (add) {
                            add.focus();
                        }
                    }
                }, 'Quitar')
            ));
        });

        wrap.appendChild(h('div', { class: 'cld-field' },
            h('p', { class: 'cld-label', text: 'Días con otro horario' }),
            h('p', { class: 'cld-help', text: 'Por ejemplo 24 y 31 de diciembre. Ese día manda este horario. Los días sin horas completas no se guardan.' }),
            draft.exceptions.length ? list : null,
            h('div', {},
                h('button', {
                    type: 'button',
                    class: 'cld-btn cld-btn--outline',
                    id: 'cld-ex-add',
                    onClick: function () {
                        draft.exceptions.push({ date: '', start: '', end: '' });
                        rerenderDetailKeepingScroll();
                        var input = main.querySelector('#cld-ex-date-' + (draft.exceptions.length - 1));

                        if (input) {
                            input.focus();
                        }
                    }
                }, icon('plus'), 'Añadir día especial')
            )
        ));

        return wrap;
    }

    function eventBar() {
        var events = state.events;
        var isNew = events.draft && !events.draft.id;

        return saveBar({
            dirty: isDirty('events') || isNew,
            alwaysSave: isNew,
            saving: events.saving,
            error: events.errors._bar || '',
            dirtyText: isNew ? 'Evento sin guardar' : 'Cambios sin guardar',
            cleanText: 'Guardado',
            saveLabel: isNew ? 'Guardar evento' : 'Guardar cambios',
            onSave: function () { saveEvent(); },
            onDiscard: isNew ? null : function () {
                events.draft = eventDraftFrom(findEvent(events.draft.id));
                events.savedJson = JSON.stringify(events.draft);
                events.errors = {};
                render();
                announce('Cambios descartados.');
            }
        });
    }

    function refreshEventChrome() {
        var name = main.querySelector('[data-cld-detail-name]');

        if (name && state.events.draft) {
            name.textContent = state.events.draft.title || 'Sin nombre';
        }

        replaceBar(eventBar());
    }

    function saveEvent() {
        var events = state.events;
        var draft = events.draft;

        if (!draft || events.saving) {
            return Promise.resolve(false);
        }

        if (!draft.title.trim()) {
            events.errors = { title: 'Escribe el nombre del evento.', _bar: 'Falta el nombre del evento.' };
            rerenderDetailKeepingScroll();
            focusFirstError();

            return Promise.resolve(false);
        }

        events.saving = true;
        events.errors = {};
        replaceBar(eventBar());

        draft.url = normalizeUrl(draft.url);

        var payload = clone(draft);
        delete payload.image_url;

        return api(draft.id ? '/events/' + draft.id : '/events', {
            method: draft.id ? 'PUT' : 'POST',
            data: payload
        }).then(function (saved) {
            var list = state.data.events;
            var replaced = false;

            for (var i = 0; i < list.length; i++) {
                if (list[i].id === saved.id) {
                    list[i] = saved;
                    replaced = true;
                }
            }

            if (!replaced) {
                list.push(saved);
            }

            events.saving = false;
            events.selected = saved.id;
            events.draft = eventDraftFrom(saved);
            events.savedJson = JSON.stringify(events.draft);
            render();
            toast(saved.visible ? 'Evento guardado. Ya aparece en la web.' : 'Guardado como oculto: todavía no se ve en la web.');

            return true;
        }).catch(function (error) {
            events.saving = false;
            var fields = errorFields(error);
            var count = Object.keys(fields).length;

            events.errors = fields;
            events.errors._bar = count
                ? (count === 1 ? 'Revisa el dato marcado en rojo.' : 'Revisa los ' + count + ' datos marcados en rojo.')
                : errorMessage(error);

            if (!Object.keys(errorFields(error)).length) {
                events.errors._general = errorMessage(error);
            }

            rerenderDetailKeepingScroll();
            focusFirstError();

            return false;
        });
    }

    function focusFirstError() {
        var invalid = main.querySelector('[aria-invalid="true"], .cld-repeat .cld-error');

        if (!invalid) {
            return;
        }

        if (invalid.classList.contains('cld-error')) {
            invalid = invalid.closest('li').querySelector('input');
        }

        if (invalid) {
            invalid.focus();
        }
    }

    function deleteEvent() {
        var events = state.events;
        var draft = events.draft;

        confirmDialog({
            title: '¿Quitar «' + (draft.title || 'este evento') + '» de la web?',
            text: 'Irá a la papelera de WordPress. Un administrador puede recuperarlo.',
            actions: [
                { label: 'Quitar evento', value: 'delete', className: 'cld-btn--danger-solid' },
                { label: 'Cancelar', value: 'cancel' }
            ]
        }).then(function (choice) {
            if (choice !== 'delete') {
                return;
            }

            api('/events/' + draft.id, { method: 'DELETE' }).then(function () {
                state.data.events = state.data.events.filter(function (event) { return event.id !== draft.id; });
                events.draft = null;
                events.savedJson = '';
                events.selected = null;
                events.view = 'list';
                render();
                toast('Evento quitado de la web.');
            }).catch(function (error) {
                events.errors = { _bar: errorMessage(error) };
                replaceBar(eventBar());
            });
        });
    }

    function emptyBlock(title, text) {
        return h('div', { class: 'cld-empty' }, h('strong', { text: title }), h('span', { text: text }));
    }

    /* ==============================
     *  Arranque
     * ============================== */

    function load() {
        state.loadError = '';
        state.data = null;
        render();

        api('/overview').then(function (data) {
            state.data = data;
            state.heroes.key = '';
            render();
        }).catch(function (error) {
            state.loadError = errorMessage(error);
            render();
        });
    }

    state.section = sectionFromHash();
    decorateNav();
    syncHash();
    load();
})();
