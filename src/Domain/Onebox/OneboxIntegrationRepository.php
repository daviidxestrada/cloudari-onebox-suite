<?php
namespace Cloudari\Onebox\Domain\Onebox;

final class OneboxIntegrationRepository
{
    private const OPTION_INTEGRATIONS = 'cloudari_onebox_integrations';
    private const OPTION_ACTIVE = 'cloudari_onebox_active_integration';

    /**
     * Devuelve todas las integraciones guardadas (array slug => OneboxIntegration)
     */
    public static function all(): array
    {
        $raw = get_option(self::OPTION_INTEGRATIONS, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        $integrations = [];
        foreach ($raw as $slug => $data) {
            if (!is_array($data)) {
                $data = [];
            }
            $data['slug'] = $slug;
            $integrations[$slug] = OneboxIntegration::fromArray($data);
        }

        if (empty($integrations)) {
            $default = OneboxIntegration::fromArray(['slug' => 'default']);
            $integrations['default'] = $default;
            update_option(self::OPTION_INTEGRATIONS, ['default' => $default->toArray()]);
            update_option(self::OPTION_ACTIVE, 'default');
        }

        return $integrations;
    }

    public static function get(string $slug): OneboxIntegration
    {
        $all = self::all();
        if (isset($all[$slug])) {
            return $all[$slug];
        }

        return OneboxIntegration::fromArray(['slug' => 'default']);
    }

    public static function getActive(): OneboxIntegration
    {
        $activeSlug = get_option(self::OPTION_ACTIVE, 'default');
        return self::get($activeSlug);
    }

    public static function save(OneboxIntegration $integration): void
    {
        $all = get_option(self::OPTION_INTEGRATIONS, []);
        if (!is_array($all)) {
            $all = [];
        }

        $all[$integration->slug] = $integration->toArray();
        update_option(self::OPTION_INTEGRATIONS, $all);
        update_option(self::OPTION_ACTIVE, $integration->slug);
    }

    public static function delete(string $slug): void
    {
        $all = get_option(self::OPTION_INTEGRATIONS, []);
        if (!is_array($all)) {
            $all = [];
        }

        if (!isset($all[$slug])) {
            return;
        }

        unset($all[$slug]);
        update_option(self::OPTION_INTEGRATIONS, $all);

        $activeSlug = get_option(self::OPTION_ACTIVE, 'default');
        if ($activeSlug === $slug) {
            $newActive = array_key_first($all);
            if ($newActive === null) {
                $default = OneboxIntegration::fromArray(['slug' => 'default']);
                update_option(self::OPTION_INTEGRATIONS, ['default' => $default->toArray()]);
                update_option(self::OPTION_ACTIVE, 'default');
            } else {
                update_option(self::OPTION_ACTIVE, $newActive);
            }
        }
    }
}
