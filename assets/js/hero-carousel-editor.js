/**
 * Cloudari Hero Carrusel - vista previa del panel de Elementor
 *
 * El control MEDIA de Elementor no sabe previsualizar un campo que acepta a la vez
 * imagen y vídeo: con `media_types => ['image', 'video']` la plantilla solo pinta
 * un `<video>` sin estilos, y el JS, que por defecto toma el primer tipo, intenta
 * poner la imagen de fondo en un `<div>` que no existe. El resultado es que el
 * cuadro se queda vacío elija lo que se elija.
 *
 * Aquí se completa `applySavedValue()` solo para esos controles mixtos: se decide
 * el tipo por el archivo elegido y se muestra la imagen, o el vídeo con su
 * miniatura (la "Imagen de reserva del vídeo" del cartel, la imagen destacada del
 * adjunto o, en último caso, el primer fotograma).
 */
(function ($) {
    'use strict';

    var VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogv', 'ogg', 'm4v', 'mov'];
    var POSTER_SETTING = 'video_poster';
    var ROOT_CLASS = 'cld-mixed-media';
    var IMAGE_CLASS = 'cld-mixed-media__image';
    var BOUND_FLAG = 'cldMixedMediaBound';

    function isMixedControl(view) {
        var types = view.model.get('media_types') || [];

        return types.indexOf('image') !== -1 && types.indexOf('video') !== -1;
    }

    function isVideoUrl(url) {
        var path = String(url || '').split(/[?#]/)[0];
        var extension = path.split('.').pop().toLowerCase();

        return VIDEO_EXTENSIONS.indexOf(extension) !== -1;
    }

    /** WordPress devuelve el icono genérico del tipo MIME cuando un vídeo no tiene imagen destacada. */
    function isMimeIcon(url) {
        return /\/wp-includes\/images\/media\//.test(String(url || ''));
    }

    function getSettings(view) {
        return view.container ? view.container.settings : null;
    }

    function getPosterUrl(view) {
        var settings = getSettings(view);
        var poster = settings ? settings.get(POSTER_SETTING) : null;

        return poster && poster.url ? poster.url : '';
    }

    function getAttachment(id) {
        if (!id || !window.wp || !wp.media || typeof wp.media.attachment !== 'function') {
            return null;
        }

        return wp.media.attachment(id);
    }

    function getImageLayer($area) {
        var $image = $area.find('.' + IMAGE_CLASS);

        if (!$image.length) {
            // Misma clase que la vista previa nativa para heredar tamaño y `cover`.
            $image = $('<div class="elementor-control-media__preview ' + IMAGE_CLASS + '"></div>');
            $area.append($image);
        }

        return $image;
    }

    function showImage(view, $area, url) {
        var $video = $area.find('.elementor-control-media-video');

        // Sin `src` el navegador no sigue descargando un vídeo que ya no se ve.
        $video.removeAttr('src').removeAttr('poster');
        getImageLayer($area).css('background-image', 'url("' + url + '")');
        view.ui.controlMedia.removeClass(ROOT_CLASS + '--video').addClass(ROOT_CLASS + '--image');
    }

    function showVideo(view, $area, url) {
        var $video = $area.find('.elementor-control-media-video');

        if (!$video.length) {
            $video = $('<video class="elementor-control-media-video" preload="metadata"></video>');
            $area.append($video);
        }

        $video.prop('muted', true).attr({ playsinline: '', muted: '' });

        // `#t=0.1` obliga a pintar un fotograma cuando no hay miniatura.
        var src = url.indexOf('#') === -1 ? url + '#t=0.1' : url;

        if ($video.attr('src') !== src) {
            $video.attr('src', src);
        }

        getImageLayer($area).css('background-image', '');
        view.ui.controlMedia.removeClass(ROOT_CLASS + '--image').addClass(ROOT_CLASS + '--video');
        applyPoster(view, $video);
    }

    function applyPoster(view, $video) {
        var poster = getPosterUrl(view);

        if (poster) {
            $video.attr('poster', poster);
            return;
        }

        $video.removeAttr('poster');

        var attachment = getAttachment(view.getControlValue('id'));

        if (!attachment) {
            return;
        }

        var expectedUrl = view.getControlValue('url');

        attachment.fetch().then(function () {
            var thumb = attachment.get('thumb') || attachment.get('image') || {};

            // La respuesta puede llegar cuando ya se ha elegido otro archivo o una imagen de reserva.
            if (view.getControlValue('url') !== expectedUrl || getPosterUrl(view)) {
                return;
            }

            if (thumb.src && !isMimeIcon(thumb.src)) {
                $video.attr('poster', thumb.src);
            }
        });
    }

    function renderPreview(view) {
        var $area = view.$el.find('.elementor-control-media-area');

        if (!$area.length) {
            return;
        }

        view.ui.controlMedia.addClass(ROOT_CLASS);

        var url = view.getControlValue('url') || '';

        if (!url) {
            $area.find('.elementor-control-media-video').removeAttr('src').removeAttr('poster');
            getImageLayer($area).css('background-image', '');
            view.ui.controlMedia.removeClass(ROOT_CLASS + '--image ' + ROOT_CLASS + '--video');
            return;
        }

        if (isVideoUrl(url)) {
            showVideo(view, $area, url);
            return;
        }

        showImage(view, $area, url);

        // Un vídeo con una extensión poco común solo se reconoce por el adjunto.
        var attachment = getAttachment(view.getControlValue('id'));

        if (attachment) {
            attachment.fetch().then(function () {
                if (attachment.get('type') === 'video' && view.getControlValue('url') === url) {
                    showVideo(view, $area, url);
                }
            });
        }
    }

    function bindPosterUpdates(view) {
        var settings = getSettings(view);

        if (view[BOUND_FLAG] || !settings || view.model.get('name') === POSTER_SETTING) {
            return;
        }

        view[BOUND_FLAG] = true;

        // Cambiar la imagen de reserva actualiza al momento la miniatura de los dos medios.
        view.listenTo(settings, 'change:' + POSTER_SETTING, function () {
            renderPreview(view);
        });
    }

    function patch() {
        var Media = window.elementor && elementor.modules && elementor.modules.controls
            ? elementor.modules.controls.Media
            : null;

        if (!Media || Media.prototype.cldMixedMediaPatched) {
            return;
        }

        var applySavedValue = Media.prototype.applySavedValue;

        Media.prototype.cldMixedMediaPatched = true;
        Media.prototype.applySavedValue = function () {
            applySavedValue.apply(this, arguments);

            if (!isMixedControl(this)) {
                return;
            }

            try {
                renderPreview(this);
                bindPosterUpdates(this);
            } catch (error) {
                // Si Elementor cambia su marcado, mejor una vista previa vacía que un panel roto.
                if (window.console) {
                    window.console.warn('[Cloudari] Vista previa de medio no disponible:', error);
                }
            }
        };
    }

    patch();
    $(window).on('elementor:init', patch);
})(jQuery);
