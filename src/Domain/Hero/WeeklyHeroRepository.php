<?php

namespace Cloudari\Onebox\Domain\Hero;

if (!defined('ABSPATH')) {
    exit;
}

final class WeeklyHeroRepository
{
    private const OPTION_NAME = 'cloudari_weekly_hero_slides';

    public static function defaults(): array
    {
        return [
            [
                'enabled' => true,
                'title' => 'We Will Rock You Especial 25 anos',
                'url' => 'https://tickets.oneboxtds.com/laestacion/events/16613',
                'desktop_image' => 'https://laestacion.com/wp-content/uploads/2026/04/1920x8001.jpg',
                'mobile_image' => 'https://laestacion.com/wp-content/uploads/2026/04/WWRY-web-movil21-scaled.jpg',
                'alt' => 'Cartel promocional We Will Rock You Especial 25 anos',
            ],
            [
                'enabled' => true,
                'title' => 'Que razon tenia mi padre',
                'url' => 'https://tickets.oneboxtds.com/laestacion/events/46653',
                'desktop_image' => 'https://laestacion.com/wp-content/uploads/2026/05/HERO-RAZONMIPADRE-1920-x-800-px.jpg',
                'mobile_image' => 'https://laestacion.com/wp-content/uploads/2026/05/HERO.QUERAZON.TENIA_.MI_.PADRE_.jpg',
                'alt' => 'Que razon tenia mi padre',
            ],
            [
                'enabled' => true,
                'title' => 'We Love Disco El Musical',
                'url' => 'https://tickets.oneboxtds.com/laestacion/events/53056',
                'desktop_image' => 'https://laestacion.com/wp-content/uploads/2026/05/WebTeatro.jpg',
                'mobile_image' => 'https://laestacion.com/wp-content/uploads/2026/05/HERE.WEB_.WELOVEDISCO.jpg',
                'alt' => 'We Love Disco El Musical',
            ],
            [
                'enabled' => true,
                'title' => 'FlamencOh',
                'url' => 'https://tickets.oneboxtds.com/laestacion/events/51999',
                'desktop_image' => 'https://laestacion.com/wp-content/uploads/2026/05/FlamencOh-2500x1143-1.jpg',
                'mobile_image' => 'https://laestacion.com/wp-content/uploads/2026/05/FlamencOh-2560x1810-1.jpg',
                'alt' => 'FlamencOh flamenco comedy show',
            ],
        ];
    }

    public static function all(bool $includeDisabled = false): array
    {
        $saved = get_option(self::OPTION_NAME, null);
        $slides = is_array($saved) ? $saved : self::defaults();
        $slides = self::sanitizeSlides($slides);

        if ($includeDisabled) {
            return $slides;
        }

        return array_values(array_filter($slides, static function (array $slide): bool {
            return !empty($slide['enabled'])
                && $slide['url'] !== ''
                && $slide['desktop_image'] !== '';
        }));
    }

    public static function save(array $slides): void
    {
        update_option(self::OPTION_NAME, self::sanitizeSlides($slides), false);
    }

    public static function orderedForWeek(): array
    {
        $slides = self::all();

        if (count($slides) < 2) {
            return $slides;
        }

        $seed = self::weekSeed();

        usort($slides, static function (array $left, array $right) use ($seed): int {
            $leftHash = sprintf('%u', crc32($seed . '|' . ($left['url'] ?? '') . '|' . ($left['title'] ?? '')));
            $rightHash = sprintf('%u', crc32($seed . '|' . ($right['url'] ?? '') . '|' . ($right['title'] ?? '')));

            return $leftHash <=> $rightHash;
        });

        return array_values($slides);
    }

    private static function weekSeed(): string
    {
        $timestamp = current_time('timestamp');

        return wp_date('o-W', $timestamp);
    }

    private static function sanitizeSlides(array $slides): array
    {
        $normalized = [];

        foreach ($slides as $slide) {
            if (!is_array($slide)) {
                continue;
            }

            $title = sanitize_text_field(wp_unslash((string) ($slide['title'] ?? '')));
            $url = esc_url_raw(trim((string) ($slide['url'] ?? '')));
            $desktopImage = esc_url_raw(trim((string) ($slide['desktop_image'] ?? '')));
            $mobileImage = esc_url_raw(trim((string) ($slide['mobile_image'] ?? '')));
            $alt = sanitize_text_field(wp_unslash((string) ($slide['alt'] ?? '')));

            if ($title === '' && $url === '' && $desktopImage === '' && $mobileImage === '' && $alt === '') {
                continue;
            }

            $normalized[] = [
                'enabled' => !empty($slide['enabled']),
                'title' => $title,
                'url' => $url,
                'desktop_image' => $desktopImage,
                'mobile_image' => $mobileImage,
                'alt' => $alt !== '' ? $alt : $title,
            ];
        }

        return $normalized;
    }
}
