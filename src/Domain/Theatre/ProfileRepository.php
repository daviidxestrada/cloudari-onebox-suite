<?php
namespace Cloudari\Onebox\Domain\Theatre;

final class ProfileRepository
{
    private const OPTION_PROFILES = 'cloudari_onebox_profiles';
    private const OPTION_ACTIVE   = 'cloudari_onebox_active_profile';
    private const LEGACY_BILLBOARD_CTA = '#D14100';
    private const LEGACY_BILLBOARD_CARD_BG = '#FFFFFF';
    private const LEGACY_BILLBOARD_TEXT = '#0B0F1A';
    private const LEGACY_COUNTDOWN_BG = '#FFFFFF';
    private const LEGACY_COUNTDOWN_TITLE = '#004743';
    private const LEGACY_COUNTDOWN_BORDER = '#E6E6E6';
    private const LEGACY_COUNTDOWN_POSTER_CAPTION = '#FFFFFF';

    /**
     * Devuelve todos los perfiles guardados (array slug => TheatreProfile)
     */
    public static function all(): array
    {
        $raw = get_option(self::OPTION_PROFILES, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        $profiles = [];
        $didMigrate = false;
        foreach ($raw as $slug => $data) {
            if (!is_array($data)) {
                $data = [];
            }

            $data['slug'] = $slug;
            $migrated = self::migrateLegacyStyleData($data);
            if ($migrated !== $data) {
                $data = $migrated;
                $raw[$slug] = $data;
                $didMigrate = true;
            }

            $profiles[$slug] = TheatreProfile::fromArray($data);
        }

        if ($didMigrate) {
            update_option(self::OPTION_PROFILES, $raw);
        }

        if (empty($profiles)) {
            $profiles['default'] = self::defaultFromConstants();
        }

        return $profiles;
    }

    public static function get(string $slug): TheatreProfile
    {
        $all = self::all();
        if (isset($all[$slug])) {
            return $all[$slug];
        }

        return self::defaultFromConstants();
    }

    public static function getActive(): TheatreProfile
    {
        $activeSlug = get_option(self::OPTION_ACTIVE, 'default');
        return self::get($activeSlug);
    }

    public static function save(TheatreProfile $profile): void
    {
        $all = get_option(self::OPTION_PROFILES, []);
        if (!is_array($all)) {
            $all = [];
        }

        $all[$profile->slug] = $profile->toArray();
        update_option(self::OPTION_PROFILES, $all);
        update_option(self::OPTION_ACTIVE, $profile->slug);
    }

    private static function migrateLegacyStyleData(array $data): array
    {
        $selectedDay = isset($data['color_selected_day']) && is_string($data['color_selected_day'])
            ? sanitize_hex_color(trim($data['color_selected_day']))
            : null;
        $needsWidgetMigration = !array_key_exists('widget_colors', $data) || !is_array($data['widget_colors']);
        $needsSelectedDayMigration = !array_key_exists('color_selected_day', $data) || !is_string($selectedDay);

        if (!$needsWidgetMigration && !$needsSelectedDayMigration) {
            return $data;
        }

        $primary = self::sanitizeColorOrFallback($data['color_primary'] ?? '', '#009AD8');
        $accent = self::sanitizeColorOrFallback($data['color_accent'] ?? '', '#D14100');
        $text = self::sanitizeColorOrFallback($data['color_text'] ?? '', '#000000');

        if ($needsSelectedDayMigration) {
            $data['color_selected_day'] = $primary;
        }

        if ($needsWidgetMigration) {
            $themeColors = self::getLegacyThemeColors();
            $countdownTitle = self::sanitizeColorOrFallback(
                $themeColors['accent'] ?? '',
                self::LEGACY_COUNTDOWN_TITLE
            );
            $countdownBorder = self::sanitizeColorOrFallback(
                $themeColors['8'] ?? '',
                self::LEGACY_COUNTDOWN_BORDER
            );
            $countdownNumber = self::sanitizeColorOrFallback(
                $themeColors['text'] ?? '',
                $text
            );

            $data['widget_colors'] = [
                'calendar' => [
                    'nav' => $primary,
                    'text' => $text,
                ],
                'billboard' => [
                    'topbar' => $primary,
                    'cta' => self::LEGACY_BILLBOARD_CTA,
                    'card_bg' => self::LEGACY_BILLBOARD_CARD_BG,
                    'text' => self::LEGACY_BILLBOARD_TEXT,
                    'focus' => '',
                ],
                'venue_filters' => [
                    'active_bg' => 'transparent',
                    'active_text' => $accent,
                    'text' => self::LEGACY_BILLBOARD_TEXT,
                    'border' => $primary,
                    'indicator' => $accent,
                ],
                'countdown' => [
                    'panel_bg' => self::LEGACY_COUNTDOWN_BG,
                    'title' => $countdownTitle,
                    'border' => $countdownBorder,
                    'number' => $countdownNumber,
                    'poster_caption' => self::LEGACY_COUNTDOWN_POSTER_CAPTION,
                ],
            ];
        }

        return $data;
    }

    private static function sanitizeColorOrFallback($value, string $fallback): string
    {
        $sanitized = sanitize_hex_color(is_string($value) ? trim($value) : '');
        return is_string($sanitized) ? strtoupper($sanitized) : strtoupper($fallback);
    }

    private static function getLegacyThemeColors(): array
    {
        $kitId = (int) get_option('elementor_active_kit', 0);
        if ($kitId <= 0 || !function_exists('wp_upload_dir')) {
            return [];
        }

        $uploadDir = wp_upload_dir();
        $baseDir = is_array($uploadDir) ? ($uploadDir['basedir'] ?? '') : '';
        if (!is_string($baseDir) || $baseDir === '') {
            return [];
        }

        $cssFile = trailingslashit($baseDir) . 'elementor/css/post-' . $kitId . '.css';
        if (!is_readable($cssFile)) {
            return [];
        }

        $contents = file_get_contents($cssFile);
        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $colors = [];
        $matchCount = preg_match_all(
            '/--e-global-color-([A-Za-z0-9_-]+)\s*:\s*(#[0-9A-Fa-f]{3,6})/',
            $contents,
            $matches,
            PREG_SET_ORDER
        );
        if (!is_int($matchCount) || $matchCount < 1) {
            return [];
        }

        foreach ($matches as $match) {
            $key = isset($match[1]) ? trim((string) $match[1]) : '';
            $value = isset($match[2]) ? sanitize_hex_color((string) $match[2]) : null;

            if ($key === '' || !is_string($value)) {
                continue;
            }

            $colors[$key] = strtoupper($value);
        }

        return $colors;
    }

    private static function defaultFromConstants(): TheatreProfile
    {
        $channelId    = defined('ONEBOX_CHANNEL_ID')    ? ONEBOX_CHANNEL_ID    : '';
        $clientSecret = defined('ONEBOX_CLIENT_SECRET') ? ONEBOX_CLIENT_SECRET : '';

        $apiCatalog = defined('ONEBOX_API_CATALOG')
            ? ONEBOX_API_CATALOG
            : 'https://api.oneboxtds.com/catalog-api/v1';

        $apiAuth = defined('ONEBOX_API_AUTH')
            ? ONEBOX_API_AUTH
            : 'https://oauth2.oneboxtds.com/oauth/token';

        $integration = new OneboxIntegration(
            'default',
            'OneBox',
            $channelId,
            $clientSecret,
            $apiCatalog,
            $apiAuth,
            'https://tickets.oneboxtds.com/laestacion/events/'
        );

        return new TheatreProfile(
            'default',
            'Perfil por defecto',
            'La Estacion',
            '#009AD8', // primary
            '#D14100', // accent
            '#FFFFFF', // bg
            '#000000', // text
            '#009AD8', // selected day (por defecto igual que primary)
            [$integration->slug => $integration],
            $integration->slug
        );
    }
}
