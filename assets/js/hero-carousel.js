/**
 * Cloudari Hero Carrusel
 *
 * El markup lo genera PHP; aquí solo se gestiona el estado. No se construye HTML
 * en cliente, así que ningún dato de usuario vuelve a interpretarse como markup.
 */
(function () {
    'use strict';

    var SELECTOR = '[data-cld-hero]';
    var READY_FLAG = 'cldHeroReady';

    function parseConfig(root) {
        try {
            return JSON.parse(root.getAttribute('data-cld-hero-config') || '{}') || {};
        } catch (error) {
            return {};
        }
    }

    function prefersReducedMotion() {
        return typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function supportsInert() {
        return typeof HTMLElement !== 'undefined' && 'inert' in HTMLElement.prototype;
    }

    function setSlideActive(slide, isActive) {
        if (supportsInert()) {
            slide.inert = !isActive;
            return;
        }

        slide.setAttribute('aria-hidden', isActive ? 'false' : 'true');

        var focusables = slide.querySelectorAll('a[href], button');

        for (var i = 0; i < focusables.length; i++) {
            if (isActive) {
                focusables[i].removeAttribute('tabindex');
            } else {
                focusables[i].setAttribute('tabindex', '-1');
            }
        }
    }

    function init(root) {
        if (!root || root.dataset[READY_FLAG] === '1') {
            return;
        }

        var track = root.querySelector('[data-cld-hero-track]');
        var slides = root.querySelectorAll('[data-cld-hero-slide]');

        if (!track || slides.length === 0) {
            return;
        }

        root.dataset[READY_FLAG] = '1';

        var config = parseConfig(root);
        var liveRegion = root.querySelector('[data-cld-hero-live]');
        var toggle = root.querySelector('[data-cld-hero-toggle]');
        var prevButton = root.querySelector('[data-cld-hero-prev]');
        var nextButton = root.querySelector('[data-cld-hero-next]');

        var total = slides.length;
        var currentIndex = 0;
        var timer = null;
        // La pausa explícita del usuario se distingue de las automáticas, y cada
        // causa automática lleva su propio flag: si se compartieran uno solo, salir
        // con el tabulador reanudaría el carrusel con el ratón todavía encima.
        var userPaused = false;
        var autoPause = { hover: false, focus: false, hidden: false };
        var autoplayEnabled = config.autoplay === true && total > 1 && !prefersReducedMotion();
        var hasVideo = root.querySelector('[data-cld-hero-video]') !== null;
        var delay = typeof config.delay === 'number' && config.delay >= 2000 ? config.delay : 5000;

        // Solo el vídeo del cartel visible se reproduce: los demás ni se descargan
        // (preload="none") ni consumen CPU de fondo.
        function syncVideos() {
            var videos = root.querySelectorAll('[data-cld-hero-video]');

            for (var i = 0; i < videos.length; i++) {
                var video = videos[i];
                var slide = video.closest('[data-cld-hero-slide]');
                // Cuando el cartel mezcla vídeo e imagen se emiten los dos medios
                // y uno queda oculto por CSS: reproducirlo gastaría datos sin verse.
                var isVisible = video.offsetWidth > 0 || video.offsetHeight > 0;
                var shouldPlay = slide === slides[currentIndex]
                    && isVisible
                    && !userPaused
                    && !prefersReducedMotion();

                if (!shouldPlay) {
                    video.pause();
                    continue;
                }

                // Algunos navegadores solo aceptan el autoplay si el silencio está
                // fijado en la propiedad, no únicamente en el atributo.
                video.muted = true;

                var playing = video.play();

                // Safari e iOS rechazan la promesa si el navegador bloquea el
                // autoplay; queda el póster y no hay nada que reportar.
                if (playing && typeof playing.catch === 'function') {
                    playing.catch(function () {});
                }
            }
        }

        function syncSlides() {
            for (var i = 0; i < total; i++) {
                setSlideActive(slides[i], i === currentIndex);
            }

            syncVideos();
        }

        function goTo(index, announce) {
            currentIndex = ((index % total) + total) % total;
            track.style.transform = 'translate3d(-' + (currentIndex * 100) + '%, 0, 0)';
            syncSlides();

            if (announce && liveRegion) {
                liveRegion.textContent = (currentIndex + 1) + ' / ' + total;
            }
        }

        function stopTimer() {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }
        }

        function isAutoPaused() {
            return autoPause.hover || autoPause.focus || autoPause.hidden;
        }

        function startTimer() {
            stopTimer();

            if (!autoplayEnabled || userPaused || isAutoPaused()) {
                return;
            }

            timer = window.setInterval(function () {
                goTo(currentIndex + 1, false);
            }, delay);
        }

        function setAutoPaused(reason, value) {
            autoPause[reason] = value;
            startTimer();
        }

        function navigate(step) {
            // `inert` sobre el slide saliente desenfocaría al usuario hacia <body>,
            // así que si el foco venía de dentro se traslada al cartel entrante.
            var focusWasInside = track.contains(document.activeElement);

            goTo(currentIndex + step, true);

            if (focusWasInside) {
                var target = slides[currentIndex].querySelector('a[href]') || slides[currentIndex];

                if (target === slides[currentIndex]) {
                    target.setAttribute('tabindex', '-1');
                }

                target.focus();
            }

            // Reiniciar el intervalo evita que el siguiente salto automático
            // llegue a destiempo justo después de una interacción manual.
            startTimer();
        }

        if (prevButton) {
            prevButton.addEventListener('click', function () {
                navigate(-1);
            });
        }

        if (nextButton) {
            nextButton.addEventListener('click', function () {
                navigate(1);
            });
        }

        if (toggle) {
            // Un vídeo en bucle también es movimiento automático, así que el botón
            // hace falta aunque el carrusel no avance solo (WCAG 2.2.2).
            var controlsMotion = autoplayEnabled || (hasVideo && !prefersReducedMotion());

            if (!controlsMotion) {
                toggle.hidden = true;
            } else {
                // Botón de acción, no de estado: cambia solo la etiqueta. Añadir
                // aria-pressed haría que se anunciasen dos estados contradictorios.
                toggle.addEventListener('click', function () {
                    userPaused = !userPaused;
                    toggle.setAttribute('data-paused', userPaused ? 'true' : 'false');
                    toggle.setAttribute(
                        'aria-label',
                        userPaused
                            ? toggle.getAttribute('data-label-play') || 'Reanudar el carrusel'
                            : toggle.getAttribute('data-label-pause') || 'Pausar el carrusel'
                    );
                    syncVideos();
                    startTimer();
                });
            }
        }

        if (autoplayEnabled && config.pauseOnHover === true) {
            root.addEventListener('mouseenter', function () {
                setAutoPaused('hover', true);
            });

            root.addEventListener('mouseleave', function () {
                setAutoPaused('hover', false);
            });
        }

        if (autoplayEnabled) {
            root.addEventListener('focusin', function () {
                setAutoPaused('focus', true);
            });

            root.addEventListener('focusout', function (event) {
                if (!root.contains(event.relatedTarget)) {
                    setAutoPaused('focus', false);
                }
            });

            document.addEventListener('visibilitychange', function () {
                setAutoPaused('hidden', document.hidden);
            });
        }

        root.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                navigate(-1);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                navigate(1);
            }
        });

        goTo(0, false);
        startTimer();
    }

    function initAll(context) {
        var scope = context || document;
        var roots = scope.querySelectorAll(SELECTOR);

        for (var i = 0; i < roots.length; i++) {
            init(roots[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAll(document);
        });
    } else {
        initAll(document);
    }

    // Editor y previsualización de Elementor: el widget se reinyecta en cada cambio.
    // `elementor/frontend/init` se dispara con jQuery.trigger, que no despacha un
    // evento DOM nativo, así que addEventListener no serviría aquí.
    function registerElementorHook() {
        if (!window.elementorFrontend || !window.elementorFrontend.hooks) {
            return false;
        }

        window.elementorFrontend.hooks.addAction(
            'frontend/element_ready/cloudari_hero_carousel.default',
            function ($scope) {
                init($scope && $scope[0] ? $scope[0].querySelector(SELECTOR) : null);
            }
        );

        return true;
    }

    if (!registerElementorHook() && window.jQuery) {
        window.jQuery(window).on('elementor/frontend/init', registerElementorHook);
    }
})();
