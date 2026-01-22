<?php

namespace Cloudari\Onebox\Domain\Events;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestión de overrides por evento:
 * - redirect_url: URL especial de compra / landing
 * - category_key: clave canónica de categoría (teatro, musica, musical, humor, talk)
 *
 * option_name = cloudari_onebox_event_overrides
 */
final class EventOverridesRepository
{
    private const OPTION_KEY = 'cloudari_onebox_event_overrides';

    /**
     * Devuelve el array completo de overrides.
     */
    public static function all(): array
    {
        $raw = get_option(self::OPTION_KEY, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Devuelve overrides de un evento.
     */
    public static function get(int|string $eventId): array
    {
        $all = self::all();
        $key = (string) $eventId;

        if (!isset($all[$key]) || !is_array($all[$key])) {
            return [];
        }

        return [
            'redirect_url' => (string)($all[$key]['redirect_url'] ?? ''),
            'category_key' => (string)($all[$key]['category_key'] ?? ''),
        ];
    }

    /**
     * Guarda override (o elimina si vienen vacíos).
     */
    public static function save(int|string $eventId, string $redirectUrl = '', string $categoryKey = ''): void
    {
        $id = (string)$eventId;
        $redirectUrl = trim($redirectUrl);
        $categoryKey = trim($categoryKey);

        $all = self::all();

        if ($redirectUrl === '' && $categoryKey === '') {
            unset($all[$id]);
            update_option(self::OPTION_KEY, $all);
            return;
        }

        $all[$id] = [
            'redirect_url' => $redirectUrl,
            'category_key' => $categoryKey,
        ];

        update_option(self::OPTION_KEY, $all);
    }

    /**
     * Elimina override.
     */
    public static function delete(int|string $eventId): void
    {
        $id  = (string) $eventId;
        $all = self::all();

        if (isset($all[$id])) {
            unset($all[$id]);
            update_option(self::OPTION_KEY, $all);
        }
    }

    /**
     * Nuevo:
     * Devuelve los mapas listos para inyectar en JS:
     *
     * [
     *   'specialRedirects'  => [ id => url ],
     *   'categoryOverrides' => [ id => 'musica' ],
     * ]
     */
    public static function getEnvMaps(): array
    {
        $all = self::all();

        $special = [];
        $cats    = [];

        foreach ($all as $id => $row) {
            $id = (int)$id;
            if ($id <= 0) continue;

            if (!empty($row['redirect_url'])) {
                $special[$id] = (string)$row['redirect_url'];
            }

            if (!empty($row['category_key'])) {
                $cats[$id] = sanitize_key((string)$row['category_key']);
            }
        }

        return [
            'specialRedirects'  => $special,
            'categoryOverrides' => $cats,
        ];
    }
}
