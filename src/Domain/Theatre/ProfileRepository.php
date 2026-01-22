<?php
namespace Cloudari\Onebox\Domain\Theatre;

final class ProfileRepository
{
    private const OPTION_PROFILES = 'cloudari_onebox_profiles';
    private const OPTION_ACTIVE   = 'cloudari_onebox_active_profile';

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
        foreach ($raw as $slug => $data) {
            $data['slug'] = $slug;
            $profiles[$slug] = TheatreProfile::fromArray($data);
        }

        // Si no hay ninguno, crea uno por defecto (puente con constantes)
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

        // fallback: perfil por defecto
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

    private static function defaultFromConstants(): TheatreProfile
    {
        // Puente de compatibilidad: usa las constantes si existen
        $channelId    = defined('ONEBOX_CHANNEL_ID')    ? ONEBOX_CHANNEL_ID    : '';
        $clientSecret = defined('ONEBOX_CLIENT_SECRET') ? ONEBOX_CLIENT_SECRET : '';

        $apiCatalog = defined('ONEBOX_API_CATALOG')
            ? ONEBOX_API_CATALOG
            : 'https://api.oneboxtds.com/catalog-api/v1';

        $apiAuth = defined('ONEBOX_API_AUTH')
            ? ONEBOX_API_AUTH
            : 'https://oauth2.oneboxtds.com/oauth/token';

        return new TheatreProfile(
    'default',
    'Perfil por defecto',
    $channelId,
    $clientSecret,
    $apiCatalog,
    $apiAuth,
    'https://tickets.oneboxtds.com/laestacion/events/',
    'La Estación',
    '#009AD8', // primary
    '#D14100', // accent
    '#FFFFFF', // bg
    '#000000', // text
    '#009AD8'  // selected day (por defecto igual que primary)
);

    }
}
