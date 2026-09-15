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
    private function __construct(
        public readonly string $itemId,
        public readonly string $title,
        public readonly string $desktopUrl,
        public readonly string $mobileUrl,
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
        $desktopUrl = self::mediaUrl($item['image_desktop'] ?? null);

        if ($desktopUrl === '') {
            return null;
        }

        $mobileUrl = self::mediaUrl($item['image_mobile'] ?? null);
        $link = is_array($item['link'] ?? null) ? $item['link'] : [];
        $linkUrl = esc_url_raw((string) ($link['url'] ?? ''));

        return new self(
            itemId: sanitize_html_class((string) ($item['_id'] ?? '')),
            title: sanitize_text_field((string) ($item['title'] ?? '')),
            desktopUrl: $desktopUrl,
            mobileUrl: $mobileUrl !== '' ? $mobileUrl : $desktopUrl,
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
