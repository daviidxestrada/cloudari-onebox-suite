<?php

namespace Cloudari\Onebox\Presentation\Hero;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Value object de un slide del hero.
 *
 * Es la única frontera de saneado del widget: todo lo que entra por el repeater
 * de Elementor pasa por `fromRepeaterItem()` y sale ya validado, de modo que el
 * render se limita a escapar para el contexto (atributo/URL) sin decidir nada.
 */
final class HeroSlide
{
    /** Extensiones de vídeo aceptadas cuando la URL no viene de un adjunto. */
    private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogv', 'ogg', 'm4v', 'mov'];

    private function __construct(
        public readonly string $itemId,
        public readonly string $title,
        public readonly string $desktopUrl,
        public readonly string $mobileUrl,
        public readonly bool $desktopIsVideo,
        public readonly bool $mobileIsVideo,
        public readonly string $posterUrl,
        public readonly string $alt,
        public readonly string $linkUrl,
        public readonly bool $linkNewTab,
        public readonly bool $linkNofollow,
        public readonly bool $containMobile
    ) {
    }

    /**
     * @param array<string,mixed> $item Item crudo del repeater de Elementor.
     */
    public static function fromRepeaterItem(array $item): ?self
    {
        $desktop = $item['image_desktop'] ?? null;
        $desktopUrl = self::mediaUrl($desktop);

        if ($desktopUrl === '') {
            return null;
        }

        $mobile = $item['image_mobile'] ?? null;
        $mobileUrl = self::mediaUrl($mobile);
        $hasOwnMobile = $mobileUrl !== '';

        $link = is_array($item['link'] ?? null) ? $item['link'] : [];
        $linkUrl = esc_url_raw((string) ($link['url'] ?? ''));

        $desktopIsVideo = self::isVideo($desktop, $desktopUrl);

        return new self(
            itemId: sanitize_html_class((string) ($item['_id'] ?? '')),
            title: sanitize_text_field((string) ($item['title'] ?? '')),
            desktopUrl: $desktopUrl,
            mobileUrl: $hasOwnMobile ? $mobileUrl : $desktopUrl,
            desktopIsVideo: $desktopIsVideo,
            // Sin medio móvil propio se hereda el de escritorio, y con él su tipo.
            mobileIsVideo: $hasOwnMobile ? self::isVideo($mobile, $mobileUrl) : $desktopIsVideo,
            posterUrl: self::mediaUrl($item['video_poster'] ?? null),
            alt: self::resolveAlt($item, $desktopUrl),
            linkUrl: $linkUrl,
            linkNewTab: !empty($link['is_external']),
            linkNofollow: !empty($link['nofollow']),
            containMobile: ($item['contain_mobile'] ?? '') === 'yes'
        );
    }

    public function hasLink(): bool
    {
        return $this->linkUrl !== '';
    }

    public function hasVideo(): bool
    {
        return $this->desktopIsVideo || $this->mobileIsVideo;
    }

    /**
     * Imagen de relleno para el fondo desenfocado del modo "cartel completo".
     * Un `<video>` no sirve ahí: se duplicaría la descarga solo para difuminarlo.
     */
    public function backdropImageUrl(): string
    {
        if (!$this->mobileIsVideo) {
            return $this->mobileUrl;
        }

        if ($this->posterUrl !== '') {
            return $this->posterUrl;
        }

        return $this->desktopIsVideo ? '' : $this->desktopUrl;
    }

    /**
     * Etiqueta del enlace para lectores de pantalla.
     *
     * Un `aria-label` en el `<a>` sustituye al nombre accesible que aportaría el
     * `<img alt>` que envuelve, así que la descripción del cartel tiene que viajar
     * dentro de la etiqueta o se pierde: primero qué se ve, después qué hace.
     */
    public function linkLabel(): string
    {
        $action = $this->title === ''
            ? __('Comprar entradas', 'cloudari-onebox')
            /* translators: %s: nombre del espectáculo. */
            : sprintf(__('Comprar entradas para %s', 'cloudari-onebox'), $this->title);

        return $this->alt === '' ? $action : $this->alt . '. ' . $action;
    }

    public function relAttribute(): string
    {
        $rel = [];

        if ($this->linkNewTab) {
            $rel[] = 'noopener';
            $rel[] = 'noreferrer';
        }

        if ($this->linkNofollow) {
            $rel[] = 'nofollow';
        }

        return implode(' ', $rel);
    }

    private static function mediaUrl($media): string
    {
        if (!is_array($media)) {
            return '';
        }

        return esc_url_raw((string) ($media['url'] ?? ''));
    }

    /**
     * El adjunto de la Mediateca es la fuente fiable del tipo; la extensión solo
     * se consulta para URLs externas, y contra una lista cerrada.
     */
    private static function isVideo($media, string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $attachmentId = is_array($media) ? (int) ($media['id'] ?? 0) : 0;

        if ($attachmentId > 0) {
            return (bool) wp_attachment_is('video', $attachmentId);
        }

        $extension = strtolower((string) pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($extension, self::VIDEO_EXTENSIONS, true);
    }

    /**
     * Prioridad: alt escrito en el widget > alt del adjunto en la Mediateca > ''.
     * Un alt vacío es legítimo (imagen decorativa), pero solo si nadie lo definió.
     */
    private static function resolveAlt(array $item, string $desktopUrl): string
    {
        $alt = sanitize_text_field((string) ($item['alt_text'] ?? ''));

        if ($alt !== '') {
            return $alt;
        }

        $attachmentId = (int) ($item['image_desktop']['id'] ?? 0);

        if ($attachmentId > 0) {
            $alt = (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true);
        }

        return sanitize_text_field($alt);
    }
}
