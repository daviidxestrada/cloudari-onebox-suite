<?php
namespace Cloudari\Onebox\Admin;

use Cloudari\Onebox\Domain\Billboard\VenueBillboard;
use Cloudari\Onebox\Domain\Theatre\ProfileRepository;
use Cloudari\Onebox\Domain\Theatre\TheatreProfile;
use Cloudari\Onebox\Domain\Theatre\OneboxIntegration;
use Cloudari\Onebox\Infrastructure\Onebox\Auth;

final class SettingsPage
{
    private const DEFAULT_STYLE_VALUES = [
        'color_primary' => '#009AD8',
        'color_accent' => '#D14100',
        'color_bg' => '#FFFFFF',
        'color_text' => '#000000',
        'color_selected_day' => '#009AD8',
    ];

    private const GLOBAL_STYLE_LABELS = [
        'color_primary' => 'Color principal global',
        'color_accent' => 'Color de llamada a la accion global',
        'color_bg' => 'Fondo global',
        'color_text' => 'Color de texto global',
        'color_selected_day' => 'Color de dia seleccionado',
    ];

    public static function register(): void
    {
        add_menu_page(
            'Cloudari OneBox',
            'Cloudari OneBox',
            'manage_options',
            'cloudari-onebox',
            [static::class, 'render'],
            'dashicons-tickets',
            59
        );

        add_action('admin_enqueue_scripts', [static::class, 'enqueueAssets']);
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_cloudari-onebox') {
            return;
        }

        wp_enqueue_script(
            'cloudari-onebox-admin-settings',
            CLOUDARI_ONEBOX_URL . 'assets/js/admin-settings.js',
            [],
            CLOUDARI_ONEBOX_VER,
            true
        );

        wp_localize_script(
            'cloudari-onebox-admin-settings',
            'cloudariOneboxAdminStyleConfig',
            self::getAdminStyleScriptConfig()
        );
    }

    private static function sanitizeWidgetColors($raw): array
    {
        $normalized = [];

        if (!is_array($raw)) {
            return $normalized;
        }

        foreach ($raw as $widget => $tokens) {
            $widgetKey = sanitize_key((string) $widget);
            if ($widgetKey === '' || !is_array($tokens)) {
                continue;
            }

            foreach ($tokens as $token => $value) {
                $tokenKey = sanitize_key((string) $token);
                if ($tokenKey === '') {
                    continue;
                }

                $rawValue = is_string($value) ? trim($value) : '';
                if (strcasecmp($rawValue, 'transparent') === 0) {
                    $normalized[$widgetKey][$tokenKey] = 'transparent';
                    continue;
                }

                $sanitized = sanitize_hex_color($rawValue);
                $normalized[$widgetKey][$tokenKey] = is_string($sanitized) ? $sanitized : '';
            }
        }

        return $normalized;
    }

    private static function sanitizeVenueDisplayOrder($raw): array
    {
        $normalized = [];

        if (!is_array($raw)) {
            return $normalized;
        }

        foreach ($raw as $value) {
            $key = sanitize_key((string) $value);
            if ($key === '' || in_array($key, $normalized, true)) {
                continue;
            }

            $normalized[] = $key;
        }

        return $normalized;
    }

    private static function mergeVenueDisplayOrder(array $postedOrder, array $existingOrder): array
    {
        $merged = self::sanitizeVenueDisplayOrder($postedOrder);

        foreach (self::sanitizeVenueDisplayOrder($existingOrder) as $existingKey) {
            if (!in_array($existingKey, $merged, true)) {
                $merged[] = $existingKey;
            }
        }

        return $merged;
    }

    private static function sanitizeVenueSourceMappings($raw): array
    {
        $normalized = [];

        if (!is_array($raw)) {
            return $normalized;
        }

        foreach ($raw as $sourceKey => $row) {
            $key = sanitize_key((string) $sourceKey);
            if ($key === '') {
                continue;
            }

            $canonicalName = '';
            if (is_array($row)) {
                $canonicalName = sanitize_text_field(
                    is_string($row['canonical_name'] ?? null)
                        ? wp_unslash($row['canonical_name'])
                        : ''
                );
            } elseif (is_string($row)) {
                $canonicalName = sanitize_text_field(wp_unslash($row));
            }

            if ($canonicalName === '') {
                $normalized[$key] = [];
                continue;
            }

            $canonicalSlug = sanitize_title($canonicalName);
            if ($canonicalSlug === '') {
                $canonicalSlug = 'venue-' . substr(md5(strtolower($canonicalName)), 0, 12);
            }

            $normalized[$key] = [
                'canonical_name' => $canonicalName,
                'canonical_slug' => $canonicalSlug,
            ];
        }

        return $normalized;
    }

    private static function mergeVenueSourceMappings($postedMappings, array $existingMappings): array
    {
        $merged = [];

        foreach (self::sanitizeVenueSourceMappings($existingMappings) as $sourceKey => $mapping) {
            if (!empty($mapping)) {
                $merged[$sourceKey] = $mapping;
            }
        }

        if (!is_array($postedMappings)) {
            return $merged;
        }

        $sanitizedPosted = self::sanitizeVenueSourceMappings($postedMappings);

        foreach ($postedMappings as $sourceKey => $unused) {
            $key = sanitize_key((string) $sourceKey);
            if ($key === '') {
                continue;
            }

            if (empty($sanitizedPosted[$key])) {
                unset($merged[$key]);
                continue;
            }

            $merged[$key] = $sanitizedPosted[$key];
        }

        return $merged;
    }

    private static function getVenueOrderKey(array $venue): string
    {
        $candidates = [
            $venue['id'] ?? '',
            $venue['slug'] ?? '',
            $venue['name'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $key = sanitize_key((string) $candidate);
            if ($key !== '') {
                return $key;
            }
        }

        return '';
    }

    private static function formatVenuePriorityMeta(array $venue): string
    {
        $parts = [];
        $eventCount = isset($venue['event_count']) ? (int) $venue['event_count'] : 0;

        if ($eventCount > 0) {
            $parts[] = sprintf(
                _n('%d evento visible', '%d eventos visibles', $eventCount, 'cloudari-onebox'),
                $eventCount
            );
        }

        $nextStart = trim((string) ($venue['next_start'] ?? ''));
        $timestamp = $nextStart !== '' ? strtotime($nextStart) : false;
        if ($timestamp !== false) {
            $parts[] = sprintf(
                'Proxima fecha: %s',
                wp_date('d/m/Y H:i', $timestamp, wp_timezone())
            );
        }

        return implode(' | ', $parts);
    }

    private static function formatVenueSourceMappingMeta(array $sourceVenue, string $integrationLabel): string
    {
        $parts = [];
        $source = sanitize_key((string) ($sourceVenue['source'] ?? ''));

        $parts[] = $source === 'manual'
            ? 'Fuente: Eventos manuales'
            : 'Fuente: ' . $integrationLabel;

        $rawId = trim((string) ($sourceVenue['raw_id'] ?? ''));
        if ($rawId !== '') {
            $parts[] = 'ID venue: ' . $rawId;
        }

        $sessionsCount = isset($sourceVenue['sessions_count']) ? (int) $sourceVenue['sessions_count'] : 0;
        if ($sessionsCount > 0) {
            $parts[] = sprintf(
                _n('%d sesion detectada', '%d sesiones detectadas', $sessionsCount, 'cloudari-onebox'),
                $sessionsCount
            );
        }

        $nextStart = trim((string) ($sourceVenue['next_start'] ?? ''));
        $timestamp = $nextStart !== '' ? strtotime($nextStart) : false;
        if ($timestamp !== false) {
            $parts[] = 'Proxima fecha: ' . wp_date('d/m/Y H:i', $timestamp, wp_timezone());
        }

        return implode(' | ', $parts);
    }

    private static function getVenuePriorityRows(TheatreProfile $profile): array
    {
        $rows = [];

        try {
            $payload = VenueBillboard::get();
            $venues = isset($payload['data']) && is_array($payload['data'])
                ? $payload['data']
                : [];
        } catch (\Throwable $e) {
            $venues = [];
        }

        foreach ($venues as $venue) {
            if (!is_array($venue)) {
                continue;
            }

            $key = self::getVenueOrderKey($venue);
            if ($key === '' || isset($rows[$key])) {
                continue;
            }

            $name = trim((string) ($venue['name'] ?? ''));
            $rows[$key] = [
                'key'  => $key,
                'name' => $name !== '' ? $name : $key,
                'meta' => self::formatVenuePriorityMeta($venue),
            ];
        }

        $orderedRows = [];

        foreach ($profile->venueDisplayOrder as $key) {
            if (!isset($rows[$key])) {
                continue;
            }

            $orderedRows[] = $rows[$key];
            unset($rows[$key]);
        }

        foreach ($rows as $row) {
            $orderedRows[] = $row;
        }

        return $orderedRows;
    }

    private static function getVenueSourceMappingRows(TheatreProfile $profile): array
    {
        $rows = [];

        try {
            $payload = VenueBillboard::get();
            $venues = isset($payload['data']) && is_array($payload['data'])
                ? $payload['data']
                : [];
        } catch (\Throwable $e) {
            $venues = [];
        }

        foreach ($venues as $venue) {
            if (!is_array($venue)) {
                continue;
            }

            $sourceContext = isset($venue['source_context']) && is_array($venue['source_context'])
                ? $venue['source_context']
                : [];

            $sourceVenues = isset($sourceContext['source_venues']) && is_array($sourceContext['source_venues'])
                ? $sourceContext['source_venues']
                : [];

            foreach ($sourceVenues as $sourceVenue) {
                if (!is_array($sourceVenue)) {
                    continue;
                }

                $sourceKey = sanitize_key((string) ($sourceVenue['source_key'] ?? ''));
                if ($sourceKey === '' || isset($rows[$sourceKey])) {
                    continue;
                }

                $integration = sanitize_key((string) ($sourceVenue['integration'] ?? ''));
                $source = sanitize_key((string) ($sourceVenue['source'] ?? ''));
                $integrationLabel = $source === 'manual'
                    ? 'Eventos manuales'
                    : (
                        ($profile->getIntegration($integration)?->label)
                            ?: ($integration !== '' ? $integration : 'OneBox')
                    );

                $rawName = trim((string) ($sourceVenue['raw_name'] ?? ''));
                $mapping = isset($profile->venueSourceMappings[$sourceKey]) && is_array($profile->venueSourceMappings[$sourceKey])
                    ? $profile->venueSourceMappings[$sourceKey]
                    : [];
                $mappingValue = trim((string) ($mapping['canonical_name'] ?? ''));
                $currentCanonical = $mappingValue !== '' ? $mappingValue : ($rawName !== '' ? $rawName : $sourceKey);

                $rows[$sourceKey] = [
                    'source_key'            => $sourceKey,
                    'source'                => $source,
                    'integration'           => $integration,
                    'integration_label'     => $integrationLabel,
                    'raw_name'              => $rawName !== '' ? $rawName : $sourceKey,
                    'raw_id'                => trim((string) ($sourceVenue['raw_id'] ?? '')),
                    'mapping_value'         => $mappingValue,
                    'current_canonical'     => $currentCanonical,
                    'meta'                  => self::formatVenueSourceMappingMeta($sourceVenue, $integrationLabel),
                    'is_missing'            => false,
                ];
            }
        }

        foreach ($profile->venueSourceMappings as $sourceKey => $mapping) {
            $key = sanitize_key((string) $sourceKey);
            if ($key === '' || isset($rows[$key])) {
                continue;
            }

            $canonicalName = is_array($mapping)
                ? trim((string) ($mapping['canonical_name'] ?? ''))
                : '';

            $rows[$key] = [
                'source_key'            => $key,
                'source'                => '',
                'integration'           => '',
                'integration_label'     => 'Origen guardado',
                'raw_name'              => $key,
                'raw_id'                => '',
                'mapping_value'         => $canonicalName,
                'current_canonical'     => $canonicalName !== '' ? $canonicalName : $key,
                'meta'                  => 'No detectado actualmente. Si vacias el campo y guardas, eliminas la regla.',
                'is_missing'            => true,
            ];
        }

        uasort(
            $rows,
            static function (array $left, array $right): int {
                $labelCmp = strcasecmp(
                    (string) ($left['integration_label'] ?? ''),
                    (string) ($right['integration_label'] ?? '')
                );
                if ($labelCmp !== 0) {
                    return $labelCmp;
                }

                return strcasecmp(
                    (string) ($left['raw_name'] ?? ''),
                    (string) ($right['raw_name'] ?? '')
                );
            }
        );

        return array_values($rows);
    }

    private static function getDefaultStyleValue(string $key): string
    {
        return self::DEFAULT_STYLE_VALUES[$key] ?? '';
    }

    private static function getGlobalStyleValue(TheatreProfile $profile, string $key): string
    {
        return match ($key) {
            'color_primary' => $profile->colorPrimary !== ''
                ? $profile->colorPrimary
                : self::getDefaultStyleValue('color_primary'),
            'color_accent' => $profile->colorAccent !== ''
                ? $profile->colorAccent
                : self::getDefaultStyleValue('color_accent'),
            'color_bg' => $profile->colorBackground !== ''
                ? $profile->colorBackground
                : self::getDefaultStyleValue('color_bg'),
            'color_text' => $profile->colorText !== ''
                ? $profile->colorText
                : self::getDefaultStyleValue('color_text'),
            'color_selected_day' => $profile->colorSelectedDay !== ''
                ? $profile->colorSelectedDay
                : (
                    $profile->colorPrimary !== ''
                        ? $profile->colorPrimary
                        : self::getDefaultStyleValue('color_selected_day')
                ),
            default => '',
        };
    }

    private static function getWidgetStyleDefinitions(): array
    {
        return [
            'calendar' => [
                'title' => 'Calendario',
                'description' => 'Ajustes especificos del calendario. El dia seleccionado sigue teniendo su propio color base.',
                'legacy_fields' => [
                    'color_selected_day' => [
                        'label' => 'Color de dia seleccionado',
                        'description' => 'Color base del dia activo en el calendario.',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_primary',
                                'label' => self::GLOBAL_STYLE_LABELS['color_primary'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_primary'),
                            ],
                        ],
                    ],
                ],
                'fields' => [
                    'nav' => [
                        'label' => 'Color de flecha',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_primary',
                                'label' => self::GLOBAL_STYLE_LABELS['color_primary'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_primary'),
                            ],
                        ],
                    ],
                    'text' => [
                        'label' => 'Color de texto',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_text',
                                'label' => self::GLOBAL_STYLE_LABELS['color_text'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_text'),
                            ],
                        ],
                    ],
                ],
            ],
            'billboard' => [
                'title' => 'Cartelera',
                'description' => 'Overrides para las cards y el CTA de la cartelera.',
                'fields' => [
                    'topbar' => [
                        'label' => 'Color barra superior',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_primary',
                                'label' => self::GLOBAL_STYLE_LABELS['color_primary'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_primary'),
                            ],
                        ],
                    ],
                    'cta' => [
                        'label' => 'Color boton CTA',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_accent',
                                'label' => self::GLOBAL_STYLE_LABELS['color_accent'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_accent'),
                            ],
                        ],
                    ],
                    'card_bg' => [
                        'label' => 'Color fondo tarjeta',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_bg',
                                'label' => self::GLOBAL_STYLE_LABELS['color_bg'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_bg'),
                            ],
                        ],
                    ],
                    'text' => [
                        'label' => 'Color texto tarjeta',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_text',
                                'label' => self::GLOBAL_STYLE_LABELS['color_text'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_text'),
                            ],
                        ],
                    ],
                    'focus' => [
                        'label' => 'Color focus de controles',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_primary',
                                'label' => self::GLOBAL_STYLE_LABELS['color_primary'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_primary'),
                            ],
                        ],
                    ],
                ],
            ],
            'venue_filters' => [
                'title' => 'Sistema de filtros por espacios',
                'description' => 'Colores de las tabs/filtros que cambian de espacio en la cartelera por venues.',
                'fields' => [
                    'active_bg' => [
                        'label' => 'Color fondo tab activa',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'literal',
                                'value' => 'Transparente',
                            ],
                        ],
                    ],
                    'active_text' => [
                        'label' => 'Color texto tab activa',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_accent',
                                'label' => self::GLOBAL_STYLE_LABELS['color_accent'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_accent'),
                            ],
                        ],
                    ],
                    'text' => [
                        'label' => 'Color texto tabs inactivas',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_text',
                                'label' => self::GLOBAL_STYLE_LABELS['color_text'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_text'),
                            ],
                        ],
                    ],
                    'border' => [
                        'label' => 'Color linea base',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_primary',
                                'label' => self::GLOBAL_STYLE_LABELS['color_primary'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_primary'),
                            ],
                        ],
                    ],
                    'indicator' => [
                        'label' => 'Color indicador activo',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_accent',
                                'label' => self::GLOBAL_STYLE_LABELS['color_accent'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_accent'),
                            ],
                        ],
                    ],
                ],
            ],
            'countdown' => [
                'title' => 'Contador',
                'description' => 'Colores de fondo, titulos y cajas del widget countdown.',
                'fields' => [
                    'panel_bg' => [
                        'label' => 'Color fondo panel',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_bg',
                                'label' => self::GLOBAL_STYLE_LABELS['color_bg'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_bg'),
                            ],
                        ],
                    ],
                    'title' => [
                        'label' => 'Color titulo',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'theme',
                                'value' => 'Elementor Accent o #004743',
                            ],
                        ],
                    ],
                    'border' => [
                        'label' => 'Color borde',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'theme',
                                'value' => 'Elementor Border o #E6E6E6',
                            ],
                        ],
                    ],
                    'number' => [
                        'label' => 'Color numeros',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'global',
                                'key' => 'color_text',
                                'label' => self::GLOBAL_STYLE_LABELS['color_text'],
                            ],
                            [
                                'type' => 'literal',
                                'value' => self::getDefaultStyleValue('color_text'),
                            ],
                        ],
                    ],
                    'poster_caption' => [
                        'label' => 'Color texto poster',
                        'reset_value' => '',
                        'resolve' => [
                            [
                                'type' => 'literal',
                                'value' => '#FFFFFF',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function resolveStyleStepValue(TheatreProfile $profile, array $step): string
    {
        $type = $step['type'] ?? '';

        if ($type === 'global') {
            return self::getGlobalStyleValue($profile, (string) ($step['key'] ?? ''));
        }

        return trim((string) ($step['value'] ?? ''));
    }

    private static function resolveStyleSteps(TheatreProfile $profile, array $steps): string
    {
        foreach ($steps as $step) {
            $value = self::resolveStyleStepValue($profile, is_array($step) ? $step : []);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function describeStyleSteps(TheatreProfile $profile, array $steps): string
    {
        if (empty($steps) || !is_array($steps[0])) {
            return '';
        }

        $first = $steps[0];
        $type = $first['type'] ?? '';
        $value = self::resolveStyleStepValue($profile, $first);

        if ($type === 'global') {
            $label = trim((string) ($first['label'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($first['key'] ?? ''));
            }

            if ($label === '') {
                return $value;
            }

            return $value !== '' ? sprintf('%s (%s)', $label, $value) : $label;
        }

        return $value;
    }

    private static function getAdminStyleScriptConfig(): array
    {
        $definitions = self::getWidgetStyleDefinitions();
        $config = [
            'globalFields' => [],
            'sections' => [],
        ];

        foreach (self::DEFAULT_STYLE_VALUES as $key => $defaultValue) {
            $config['globalFields'][$key] = [
                'inputId' => $key,
                'default' => $defaultValue,
            ];
        }

        $config['globalFields']['color_selected_day']['resolve'] = [
            [
                'type' => 'global',
                'key' => 'color_primary',
                'label' => self::GLOBAL_STYLE_LABELS['color_primary'],
            ],
            [
                'type' => 'literal',
                'value' => self::getDefaultStyleValue('color_primary'),
            ],
        ];

        foreach ($definitions as $widgetKey => $section) {
            $config['sections'][$widgetKey] = [
                'legacyFields' => [],
                'fields' => [],
            ];

            foreach (($section['legacy_fields'] ?? []) as $fieldName => $field) {
                $config['sections'][$widgetKey]['legacyFields'][$fieldName] = [
                    'inputId' => $fieldName,
                    'resetValue' => (string) ($field['reset_value'] ?? ''),
                    'resolve' => $field['resolve'] ?? [],
                ];
            }

            foreach (($section['fields'] ?? []) as $fieldKey => $field) {
                $config['sections'][$widgetKey]['fields'][$fieldKey] = [
                    'inputId' => 'widget_colors_' . $widgetKey . '_' . $fieldKey,
                    'resetValue' => (string) ($field['reset_value'] ?? ''),
                    'resolve' => $field['resolve'] ?? [],
                ];
            }
        }

        return $config;
    }

    private static function getCurrentPreviewColor(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/#[0-9A-Fa-f]{3,6}\b/', $value, $matches) === 1) {
            return strtoupper($matches[0]);
        }

        if (stripos($value, 'transpar') !== false) {
            return 'transparent';
        }

        return '';
    }

    private static function getWidgetColorSections(TheatreProfile $profile): array
    {
        $sections = [];
        $definitions = self::getWidgetStyleDefinitions();

        foreach ($definitions as $widgetKey => $section) {
            $legacyFields = [];
            foreach (($section['legacy_fields'] ?? []) as $fieldName => $field) {
                $legacyFields[] = [
                    'name' => $fieldName,
                    'label' => $field['label'],
                    'value' => self::getGlobalStyleValue($profile, $fieldName),
                    'current' => self::getGlobalStyleValue($profile, $fieldName),
                    'inherit' => self::describeStyleSteps($profile, $field['resolve'] ?? []),
                    'description' => $field['description'] ?? '',
                    'placeholder' => self::resolveStyleSteps($profile, $field['resolve'] ?? []),
                ];
            }

            $fields = [];
            foreach (($section['fields'] ?? []) as $fieldKey => $field) {
                $current = $profile->getWidgetColor($widgetKey, $fieldKey);
                if ($current === '') {
                    $current = self::resolveStyleSteps($profile, $field['resolve'] ?? []);
                }

                $fields[$fieldKey] = [
                    'label' => $field['label'],
                    'inherit' => self::describeStyleSteps($profile, $field['resolve'] ?? []),
                    'current' => $current,
                ];
            }

            $sections[$widgetKey] = [
                'title' => $section['title'],
                'description' => $section['description'],
                'legacy_fields' => $legacyFields,
                'fields' => $fields,
            ];
        }

        return $sections;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active = ProfileRepository::getActive();

        if (isset($_POST['cloudari_onebox_save'])) {
            check_admin_referer('cloudari_onebox_settings');

            $slug  = 'default';
            $label = sanitize_text_field($_POST['profile_label'] ?? 'Perfil principal');

            $venueName = sanitize_text_field($_POST['venue_name'] ?? '');

            $colorPrimary = sanitize_hex_color($_POST['color_primary'] ?? '#009AD8');
            $colorAccent  = sanitize_hex_color($_POST['color_accent'] ?? '#D14100');
            $colorBg      = sanitize_hex_color($_POST['color_bg'] ?? '#FFFFFF');
            $colorText    = sanitize_hex_color($_POST['color_text'] ?? '#000000');
            $colorSelectedDay = sanitize_hex_color(
                $_POST['color_selected_day'] ?? ($colorPrimary ?: '#009AD8')
            );
            $widgetColors = self::sanitizeWidgetColors($_POST['widget_colors'] ?? []);
            $venueDisplayOrderEnabled = !empty($_POST['enable_venue_display_order']);
            $postedVenueDisplayOrder = self::sanitizeVenueDisplayOrder($_POST['venue_display_order'] ?? []);
            $venueDisplayOrder = $venueDisplayOrderEnabled
                ? self::mergeVenueDisplayOrder($postedVenueDisplayOrder, $active->venueDisplayOrder)
                : [];
            $venueSourceMappings = self::mergeVenueSourceMappings(
                $_POST['venue_source_mappings'] ?? [],
                $active->venueSourceMappings
            );

            $rawIntegrations = $_POST['integrations'] ?? [];
            $integrations = [];
            $idx = 0;

            if (is_array($rawIntegrations)) {
                foreach ($rawIntegrations as $key => $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $intSlug = sanitize_key($row['slug'] ?? $key);
                    if ($intSlug === '') {
                        $intSlug = 'integration_' . $idx;
                    }

                    $existing = $active->getIntegration($intSlug);

                    $intLabel = sanitize_text_field($row['label'] ?? '');
                    if ($intLabel === '') {
                        $intLabel = 'OneBox';
                    }

                    $channelId = sanitize_text_field($row['channel_id'] ?? '');
                    if ($channelId === '' && $existing instanceof OneboxIntegration) {
                        $channelId = $existing->channelId;
                    }

                    $clientSecret = sanitize_text_field($row['client_secret'] ?? '');
                    if ($clientSecret === '' && $existing instanceof OneboxIntegration) {
                        $clientSecret = $existing->clientSecret;
                    }

                    $apiCatalog = esc_url_raw($row['api_catalog_url'] ?? '');
                    if ($apiCatalog === '') {
                        $apiCatalog = $existing instanceof OneboxIntegration
                            ? $existing->apiCatalogUrl
                            : 'https://api.oneboxtds.com/catalog-api/v1';
                    }

                    $apiAuth = esc_url_raw($row['api_auth_url'] ?? '');
                    if ($apiAuth === '') {
                        $apiAuth = $existing instanceof OneboxIntegration
                            ? $existing->apiAuthUrl
                            : 'https://oauth2.oneboxtds.com/oauth/token';
                    }

                    $purchaseBase = esc_url_raw($row['purchase_base'] ?? '');
                    if ($purchaseBase === '' && $existing instanceof OneboxIntegration) {
                        $purchaseBase = $existing->purchaseBaseUrl;
                    }

                    $integrations[$intSlug] = new OneboxIntegration(
                        $intSlug,
                        $intLabel,
                        $channelId,
                        $clientSecret,
                        $apiCatalog,
                        $apiAuth,
                        $purchaseBase
                    );

                    $idx++;
                }
            }

            if (empty($integrations)) {
                $integrations['default'] = new OneboxIntegration(
                    'default',
                    'OneBox',
                    '',
                    '',
                    'https://api.oneboxtds.com/catalog-api/v1',
                    'https://oauth2.oneboxtds.com/oauth/token',
                    ''
                );
            }

            $defaultIntegration = sanitize_key($_POST['default_integration'] ?? '');
            if ($defaultIntegration === '' || !isset($integrations[$defaultIntegration])) {
                $defaultIntegration = (string) array_key_first($integrations);
            }

            $profile = new TheatreProfile(
                $slug,
                $label,
                $venueName,
                $colorPrimary ?: '#009AD8',
                $colorAccent ?: '#D14100',
                $colorBg ?: '#FFFFFF',
                $colorText ?: '#000000',
                $colorSelectedDay ?: ($colorPrimary ?: '#009AD8'),
                $integrations,
                $defaultIntegration,
                $widgetColors,
                $venueDisplayOrder,
                $venueSourceMappings
            );

            ProfileRepository::save($profile);
            $active = $profile;

            Auth::resetTokens();
            \Cloudari\Onebox\Rest\Routes::clearBillboardCache();

            echo '<div class="notice notice-success is-dismissible"><p>Perfil guardado correctamente. Se han refrescado las credenciales de OneBox.</p></div>';
        }

        $integrations = $active->getIntegrations();
        $defaultIntegration = $active->defaultIntegrationSlug;
        if ($defaultIntegration === '' || !isset($integrations[$defaultIntegration])) {
            $defaultIntegration = (string) array_key_first($integrations);
        }
        $widgetColors = $active->getWidgetColors();
        $widgetSections = self::getWidgetColorSections($active);
        $venuePriorityRows = self::getVenuePriorityRows($active);
        $venueSourceMappingRows = self::getVenueSourceMappingRows($active);
        $hasManualVenueDisplayOrder = !empty($active->venueDisplayOrder);
        ?>
        <div class="wrap">
            <h1>Cloudari OneBox - Perfil MAIN</h1>
            <p>Configura aqui los datos del teatro y las integraciones OneBox.</p>

            <form method="post" action="">
                <?php wp_nonce_field('cloudari_onebox_settings'); ?>

                <h2 class="title">Datos del teatro</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="profile_label">Nombre del perfil</label></th>
                        <td>
                            <input name="profile_label" id="profile_label" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr($active->label); ?>">
                            <p class="description">Solo interno, para distinguir perfiles.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="venue_name">Nombre del teatro</label></th>
                        <td>
                            <input name="venue_name" id="venue_name" type="text"
                                   class="regular-text"
                                   value="<?php echo esc_attr($active->venueName); ?>">
                            <p class="description">Se usa como texto por defecto en eventos manuales.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Prioridad de espacios en cartelera por venues</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Orden de espacios</th>
                        <td>
                            <label for="enable_venue_display_order">
                                <input
                                    type="checkbox"
                                    name="enable_venue_display_order"
                                    id="enable_venue_display_order"
                                    value="1"
                                    <?php checked($hasManualVenueDisplayOrder); ?>
                                >
                                Activar prioridad manual para los espacios
                            </label>
                            <p class="description">
                                El primer espacio de esta lista se pintara por defecto al cargar la cartelera por venues y el sistema de filtros seguira este mismo orden.
                            </p>
                            <p class="description">
                                Si esta opcion esta desactivada, se mantiene el comportamiento actual: orden automatico por proxima fecha.
                            </p>

                            <?php if (!empty($venuePriorityRows)) : ?>
                                <div
                                    class="cloudari-sortable-venues-wrap"
                                    data-cloudari-sortable-venue-wrap
                                    data-enabled="<?php echo $hasManualVenueDisplayOrder ? '1' : '0'; ?>"
                                >
                                    <ol
                                        class="cloudari-sortable-venues"
                                        data-cloudari-sortable-venue-list
                                        aria-label="Prioridad de espacios"
                                    >
                                        <?php foreach ($venuePriorityRows as $row) : ?>
                                            <li
                                                class="cloudari-sortable-venue"
                                                data-cloudari-sortable-venue-item
                                                data-venue-key="<?php echo esc_attr($row['key']); ?>"
                                                draggable="<?php echo $hasManualVenueDisplayOrder ? 'true' : 'false'; ?>"
                                            >
                                                <span class="cloudari-sortable-venue__index" data-cloudari-sortable-venue-index></span>
                                                <button
                                                    type="button"
                                                    class="button-link cloudari-sortable-venue__handle"
                                                    data-cloudari-sortable-venue-handle
                                                    aria-label="<?php echo esc_attr(sprintf('Arrastrar %s', $row['name'])); ?>"
                                                >
                                                    Arrastrar
                                                </button>
                                                <div class="cloudari-sortable-venue__content">
                                                    <strong><?php echo esc_html($row['name']); ?></strong>
                                                    <?php if ($row['meta'] !== '') : ?>
                                                        <span class="cloudari-sortable-venue__meta"><?php echo esc_html($row['meta']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <input
                                                    type="hidden"
                                                    name="venue_display_order[]"
                                                    value="<?php echo esc_attr($row['key']); ?>"
                                                >
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                    <p class="description cloudari-sortable-venues__hint" data-cloudari-sortable-venue-hint>
                                        Arrastra y suelta para cambiar el orden.
                                    </p>
                                </div>
                            <?php else : ?>
                                <p class="description">
                                    No he encontrado espacios visibles en la cartelera actual. En cuanto haya venues disponibles, podras ordenarlos desde aqui.
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Unificacion de espacios entre canales</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Equivalencias</th>
                        <td>
                            <p class="description">
                                Usa esta tabla para decirle al plugin que dos venues de canales distintos pertenecen al mismo espacio fisico.
                            </p>
                            <p class="description">
                                Para fusionarlos, escribe exactamente el mismo nombre canonico en ambas filas. Si dejas el campo vacio, se mantiene el nombre detectado actualmente.
                            </p>
                            <p class="description">
                                Esto tambien respeta los eventos manuales: puedes dejarlos tal cual o asignarlos al mismo nombre canonico si quieres que entren en la misma seccion.
                            </p>

                            <?php if (!empty($venueSourceMappingRows)) : ?>
                                <table class="widefat striped cloudari-venue-mappings-table">
                                    <thead>
                                        <tr>
                                            <th>Origen detectado</th>
                                            <th>Fuente</th>
                                            <th>Nombre canonico</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($venueSourceMappingRows as $row) : ?>
                                            <tr<?php echo !empty($row['is_missing']) ? ' class="is-missing-source"' : ''; ?>>
                                                <td>
                                                    <strong><?php echo esc_html($row['raw_name']); ?></strong>
                                                    <?php if (!empty($row['raw_id'])) : ?>
                                                        <div><code><?php echo esc_html($row['raw_id']); ?></code></div>
                                                    <?php endif; ?>
                                                    <p class="description"><?php echo esc_html((string) ($row['meta'] ?? '')); ?></p>
                                                </td>
                                                <td>
                                                    <strong><?php echo esc_html((string) ($row['integration_label'] ?? '')); ?></strong>
                                                    <?php if (!empty($row['source']) && $row['source'] === 'manual') : ?>
                                                        <p class="description">Origen manual</p>
                                                    <?php elseif (!empty($row['integration'])) : ?>
                                                        <p class="description"><code><?php echo esc_html((string) $row['integration']); ?></code></p>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <input
                                                        type="text"
                                                        class="regular-text"
                                                        name="venue_source_mappings[<?php echo esc_attr((string) $row['source_key']); ?>][canonical_name]"
                                                        value="<?php echo esc_attr((string) ($row['mapping_value'] ?? '')); ?>"
                                                        placeholder="<?php echo esc_attr((string) ($row['raw_name'] ?? '')); ?>"
                                                    >
                                                    <p class="description">
                                                        Actual en cartelera: <strong><?php echo esc_html((string) ($row['current_canonical'] ?? '')); ?></strong>
                                                    </p>
                                                    <p class="description">
                                                        Vacio = se agrupa como <strong><?php echo esc_html((string) ($row['raw_name'] ?? '')); ?></strong>
                                                    </p>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else : ?>
                                <p class="description">
                                    No hay venues detectados todavia para construir equivalencias. Cuando el plugin reciba espacios desde OneBox o eventos manuales, apareceran aqui.
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Integraciones OneBox</h2>
                <p class="description">Puedes tener varias integraciones activas dentro del mismo calendario/cartelera.</p>

                <div id="cloudari-integrations">
                    <?php if (!empty($integrations)) : ?>
                        <?php foreach ($integrations as $key => $integration) : ?>
                            <?php
                                if (!$integration instanceof OneboxIntegration) {
                                    continue;
                                }
                                $safeKey = sanitize_key((string)$key);
                                $secretId = 'cloudari_client_secret_' . $safeKey;
                                $isDefault = ($defaultIntegration === $integration->slug);
                            ?>
                            <div class="cloudari-integration" data-integration>
                                <div class="cloudari-integration-header">
                                    <strong>Integracion OneBox</strong>
                                    <label style="margin-left:12px;">
                                        <input type="radio" name="default_integration" value="<?php echo esc_attr($integration->slug); ?>" <?php checked($isDefault); ?>>
                                        Usar como default
                                    </label>
                                    <button type="button" class="button-link-delete" data-remove-integration style="margin-left:auto;">Eliminar</button>
                                </div>

                                <input type="hidden" name="integrations[<?php echo esc_attr($integration->slug); ?>][slug]" value="<?php echo esc_attr($integration->slug); ?>">

                                <table class="form-table cloudari-integration-table" role="presentation">
                                    <tr>
                                        <th scope="row"><label>Nombre (label)</label></th>
                                        <td>
                                            <input name="integrations[<?php echo esc_attr($integration->slug); ?>][label]" type="text"
                                                   class="regular-text"
                                                   value="<?php echo esc_attr($integration->label); ?>">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label>Channel ID</label></th>
                                        <td>
                                            <input name="integrations[<?php echo esc_attr($integration->slug); ?>][channel_id]" type="text"
                                                   class="regular-text"
                                                   value="<?php echo esc_attr($integration->channelId); ?>">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="<?php echo esc_attr($secretId); ?>">Client Secret</label></th>
                                        <td>
                                            <div style="display:flex; gap:8px; align-items:center; max-width:520px;">
                                                <input
                                                    type="password"
                                                    id="<?php echo esc_attr($secretId); ?>"
                                                    name="integrations[<?php echo esc_attr($integration->slug); ?>][client_secret]"
                                                    value="<?php echo esc_attr($integration->clientSecret); ?>"
                                                    class="regular-text"
                                                    autocomplete="off"
                                                    data-secret-input
                                                />
                                                <button
                                                    type="button"
                                                    class="button"
                                                    data-toggle-secret
                                                    aria-label="Mostrar client secret"
                                                >
                                                    Mostrar
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label>Catalog API URL</label></th>
                                        <td>
                                            <input name="integrations[<?php echo esc_attr($integration->slug); ?>][api_catalog_url]" type="url"
                                                   class="regular-text code"
                                                   value="<?php echo esc_url($integration->apiCatalogUrl); ?>">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label>Auth API URL</label></th>
                                        <td>
                                            <input name="integrations[<?php echo esc_attr($integration->slug); ?>][api_auth_url]" type="url"
                                                   class="regular-text code"
                                                   value="<?php echo esc_url($integration->apiAuthUrl); ?>">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label>URL base de compra</label></th>
                                        <td>
                                            <input name="integrations[<?php echo esc_attr($integration->slug); ?>][purchase_base]" type="url"
                                                   class="regular-text code"
                                                   value="<?php echo esc_url($integration->purchaseBaseUrl); ?>">
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p>
                    <button type="button" class="button" id="cloudari-add-integration">Anadir integracion</button>
                </p>

                <div class="cloudari-section-header">
                    <div>
                        <h2 class="title">Paleta global</h2>
                        <p class="description">Estos colores actuan como base compartida para todos los widgets. Debajo puedes sobrescribirlos por widget.</p>
                    </div>
                    <div class="cloudari-section-actions">
                        <button type="button" class="button button-secondary" data-reset-style="all-styles">
                            Restablecer estilos
                        </button>
                    </div>
                </div>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="color_primary">Color principal</label></th>
                        <td>
                            <input name="color_primary" id="color_primary" type="text"
                                   class="regular-text code"
                                   data-cloudari-style-input
                                   data-style-scope="global"
                                   data-style-key="color_primary"
                                   value="<?php echo esc_attr($active->colorPrimary); ?>">
                            <p class="description cloudari-color-current">
                                <span>Color actual: <code data-cloudari-style-current-code><?php echo esc_html($active->colorPrimary); ?></code></span>
                                <span class="cloudari-color-chip" data-cloudari-style-chip style="--cloudari-chip-color: <?php echo esc_attr(self::getCurrentPreviewColor($active->colorPrimary)); ?>"></span>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="color_accent">Color de llamada a la accion</label></th>
                        <td>
                            <input name="color_accent" id="color_accent" type="text"
                                   class="regular-text code"
                                   data-cloudari-style-input
                                   data-style-scope="global"
                                   data-style-key="color_accent"
                                   value="<?php echo esc_attr($active->colorAccent); ?>">
                            <p class="description cloudari-color-current">
                                <span>Color actual: <code data-cloudari-style-current-code><?php echo esc_html($active->colorAccent); ?></code></span>
                                <span class="cloudari-color-chip" data-cloudari-style-chip style="--cloudari-chip-color: <?php echo esc_attr(self::getCurrentPreviewColor($active->colorAccent)); ?>"></span>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="color_bg">Fondo widgets</label></th>
                        <td>
                            <input name="color_bg" id="color_bg" type="text"
                                   class="regular-text code"
                                   data-cloudari-style-input
                                   data-style-scope="global"
                                   data-style-key="color_bg"
                                   value="<?php echo esc_attr($active->colorBackground); ?>">
                            <p class="description cloudari-color-current">
                                <span>Color actual: <code data-cloudari-style-current-code><?php echo esc_html($active->colorBackground); ?></code></span>
                                <span class="cloudari-color-chip" data-cloudari-style-chip style="--cloudari-chip-color: <?php echo esc_attr(self::getCurrentPreviewColor($active->colorBackground)); ?>"></span>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="color_text">Color texto</label></th>
                        <td>
                            <input name="color_text" id="color_text" type="text"
                                   class="regular-text code"
                                   data-cloudari-style-input
                                   data-style-scope="global"
                                   data-style-key="color_text"
                                   value="<?php echo esc_attr($active->colorText); ?>">
                            <p class="description cloudari-color-current">
                                <span>Color actual: <code data-cloudari-style-current-code><?php echo esc_html($active->colorText); ?></code></span>
                                <span class="cloudari-color-chip" data-cloudari-style-chip style="--cloudari-chip-color: <?php echo esc_attr(self::getCurrentPreviewColor($active->colorText)); ?>"></span>
                            </p>
                        </td>
                    </tr>
                </table>

                <div class="cloudari-section-header">
                    <div>
                        <h2 class="title">Colores por widget</h2>
                        <p class="description">Si dejas un campo vacio, el widget hereda la paleta global o el fallback indicado.</p>
                    </div>
                    <div class="cloudari-section-actions">
                        <button type="button" class="button button-secondary" data-reset-style="widget-sections">
                            Restablecer todas las secciones
                        </button>
                    </div>
                </div>

                <div class="cloudari-widget-sections">
                    <?php foreach ($widgetSections as $widgetKey => $section) : ?>
                        <section class="cloudari-widget-card">
                            <div class="cloudari-widget-card-header">
                                <div>
                                    <h3><?php echo esc_html($section['title']); ?></h3>
                                    <p class="description"><?php echo esc_html($section['description']); ?></p>
                                </div>
                                <button
                                    type="button"
                                    class="button button-secondary"
                                    data-reset-style="widget-section"
                                    data-reset-section="<?php echo esc_attr($widgetKey); ?>"
                                >
                                    Restablecer esta seccion
                                </button>
                            </div>

                            <table class="form-table" role="presentation">
                                <?php foreach (($section['legacy_fields'] ?? []) as $field) : ?>
                                    <?php $legacyPreview = self::getCurrentPreviewColor((string) ($field['current'] ?? '')); ?>
                                    <tr>
                                        <th scope="row">
                                            <label for="<?php echo esc_attr($field['name']); ?>">
                                                <?php echo esc_html($field['label']); ?>
                                            </label>
                                        </th>
                                        <td>
                                            <input name="<?php echo esc_attr($field['name']); ?>"
                                                   id="<?php echo esc_attr($field['name']); ?>"
                                                   type="text"
                                                   class="regular-text code"
                                                   data-cloudari-style-input
                                                   data-style-scope="legacy"
                                                   data-style-section="<?php echo esc_attr($widgetKey); ?>"
                                                   data-style-key="<?php echo esc_attr($field['name']); ?>"
                                                   value="<?php echo esc_attr($field['value']); ?>"
                                                   placeholder="<?php echo esc_attr($field['placeholder']); ?>">
                                            <?php if (!empty($field['description'])) : ?>
                                                <p class="description"><?php echo esc_html($field['description']); ?></p>
                                            <?php endif; ?>
                                            <p class="description" data-cloudari-style-inherit>
                                                Vacio = hereda <?php echo esc_html((string) ($field['inherit'] ?? '')); ?>
                                            </p>
                                            <p class="description cloudari-color-current">
                                                <span>Color actual: <code data-cloudari-style-current-code><?php echo esc_html((string) ($field['current'] ?? '')); ?></code></span>
                                                <span class="cloudari-color-chip" data-cloudari-style-chip<?php echo $legacyPreview === '' ? ' hidden' : ''; ?> style="--cloudari-chip-color: <?php echo esc_attr($legacyPreview); ?>"></span>
                                            </p>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php foreach ($section['fields'] as $fieldKey => $field) : ?>
                                    <?php
                                        $inputId = 'widget_colors_' . $widgetKey . '_' . $fieldKey;
                                        $inputName = sprintf('widget_colors[%s][%s]', $widgetKey, $fieldKey);
                                        $value = $widgetColors[$widgetKey][$fieldKey] ?? '';
                                        $inherit = (string) ($field['inherit'] ?? '');
                                        $current = (string) ($field['current'] ?? $inherit);
                                        $preview = self::getCurrentPreviewColor($current);
                                    ?>
                                    <tr>
                                        <th scope="row">
                                            <label for="<?php echo esc_attr($inputId); ?>">
                                                <?php echo esc_html($field['label']); ?>
                                            </label>
                                        </th>
                                        <td>
                                            <input name="<?php echo esc_attr($inputName); ?>"
                                                   id="<?php echo esc_attr($inputId); ?>"
                                                   type="text"
                                                   class="regular-text code"
                                                   data-cloudari-style-input
                                                   data-style-scope="widget"
                                                   data-style-section="<?php echo esc_attr($widgetKey); ?>"
                                                   data-style-key="<?php echo esc_attr($fieldKey); ?>"
                                                   value="<?php echo esc_attr($value); ?>"
                                                   placeholder="<?php echo esc_attr($inherit); ?>">
                                            <p class="description" data-cloudari-style-inherit>
                                                Vacio = hereda <?php echo esc_html($inherit); ?>
                                            </p>
                                            <p class="description cloudari-color-current">
                                                <span>Color actual: <code data-cloudari-style-current-code><?php echo esc_html($current); ?></code></span>
                                                <span class="cloudari-color-chip" data-cloudari-style-chip<?php echo $preview === '' ? ' hidden' : ''; ?> style="--cloudari-chip-color: <?php echo esc_attr($preview); ?>"></span>
                                            </p>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </section>
                    <?php endforeach; ?>
                </div>

                <p class="submit">
                    <button type="submit" name="cloudari_onebox_save" class="button button-primary">
                        Guardar perfil
                    </button>
                </p>
            </form>

            <hr>
            <p><strong>Estado actual:</strong></p>
            <ul>
                <li>Perfil activo: <code><?php echo esc_html($active->slug); ?></code> (<?php echo esc_html($active->label); ?>)</li>
                <li>Integraciones: <?php echo count($integrations); ?></li>
            </ul>
        </div>

        <template id="cloudari-integration-template">
            <div class="cloudari-integration" data-integration>
                <div class="cloudari-integration-header">
                    <strong>Integracion OneBox</strong>
                    <label style="margin-left:12px;">
                        <input type="radio" name="default_integration" value="__KEY__">
                        Usar como default
                    </label>
                    <button type="button" class="button-link-delete" data-remove-integration style="margin-left:auto;">Eliminar</button>
                </div>

                <input type="hidden" name="integrations[__KEY__][slug]" value="__KEY__">

                <table class="form-table cloudari-integration-table" role="presentation">
                    <tr>
                        <th scope="row"><label>Nombre (label)</label></th>
                        <td>
                            <input name="integrations[__KEY__][label]" type="text"
                                   class="regular-text"
                                   value="OneBox">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Channel ID</label></th>
                        <td>
                            <input name="integrations[__KEY__][channel_id]" type="text"
                                   class="regular-text"
                                   value="">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cloudari_client_secret___KEY__">Client Secret</label></th>
                        <td>
                            <div style="display:flex; gap:8px; align-items:center; max-width:520px;">
                                <input
                                    type="password"
                                    id="cloudari_client_secret___KEY__"
                                    name="integrations[__KEY__][client_secret]"
                                    value=""
                                    class="regular-text"
                                    autocomplete="off"
                                    data-secret-input
                                />
                                <button
                                    type="button"
                                    class="button"
                                    data-toggle-secret
                                    aria-label="Mostrar client secret"
                                >
                                    Mostrar
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Catalog API URL</label></th>
                        <td>
                            <input name="integrations[__KEY__][api_catalog_url]" type="url"
                                   class="regular-text code"
                                   value="https://api.oneboxtds.com/catalog-api/v1">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Auth API URL</label></th>
                        <td>
                            <input name="integrations[__KEY__][api_auth_url]" type="url"
                                   class="regular-text code"
                                   value="https://oauth2.oneboxtds.com/oauth/token">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>URL base de compra</label></th>
                        <td>
                            <input name="integrations[__KEY__][purchase_base]" type="url"
                                   class="regular-text code"
                                   value="">
                        </td>
                    </tr>
                </table>
            </div>
        </template>

        <style>
            .cloudari-integration {
                border: 1px solid #ccd0d4;
                padding: 12px 16px;
                margin: 12px 0;
                background: #fff;
            }
            .cloudari-integration-header {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 8px;
            }
            .cloudari-integration-table {
                margin-top: 0;
            }
            .cloudari-section-header,
            .cloudari-widget-card-header {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
                flex-wrap: wrap;
            }
            .cloudari-section-header .title,
            .cloudari-widget-card-header h3 {
                margin-bottom: 4px;
            }
            .cloudari-section-actions {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .cloudari-widget-sections {
                display: grid;
                gap: 16px;
                margin-top: 12px;
            }
            .cloudari-widget-card {
                border: 1px solid #ccd0d4;
                border-radius: 8px;
                padding: 16px;
                background: #fff;
            }
            .cloudari-widget-card h3 {
                margin: 0 0 6px;
            }
            .cloudari-widget-card .form-table {
                margin-top: 8px;
            }
            .cloudari-sortable-venues-wrap {
                margin-top: 12px;
                max-width: 760px;
            }
            .cloudari-sortable-venues {
                margin: 0;
                padding: 0;
                list-style: none;
                display: grid;
                gap: 10px;
            }
            .cloudari-sortable-venue {
                display: grid;
                grid-template-columns: auto auto minmax(0, 1fr);
                align-items: center;
                gap: 12px;
                padding: 12px 14px;
                border: 1px solid #ccd0d4;
                border-radius: 8px;
                background: #fff;
                cursor: grab;
            }
            .cloudari-sortable-venue.is-dragging {
                opacity: 0.55;
                cursor: grabbing;
            }
            .cloudari-sortable-venues-wrap.is-disabled .cloudari-sortable-venue {
                opacity: 0.68;
                cursor: default;
            }
            .cloudari-sortable-venue__index {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 28px;
                height: 28px;
                border-radius: 999px;
                background: #f0f0f1;
                color: #1d2327;
                font-weight: 600;
                font-variant-numeric: tabular-nums;
            }
            .cloudari-sortable-venue__handle {
                color: #2271b1;
                text-decoration: none;
            }
            .cloudari-sortable-venue__content {
                min-width: 0;
                display: grid;
                gap: 4px;
            }
            .cloudari-sortable-venue__meta {
                color: #50575e;
            }
            .cloudari-sortable-venues__hint {
                margin-top: 8px;
            }
            .cloudari-venue-mappings-table {
                margin-top: 12px;
                max-width: 980px;
            }
            .cloudari-venue-mappings-table td {
                vertical-align: top;
            }
            .cloudari-venue-mappings-table .regular-text {
                width: 100%;
                max-width: 360px;
            }
            .cloudari-venue-mappings-table tr.is-missing-source {
                background: #fffbe6;
            }
            .cloudari-color-current {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                margin-top: 6px;
            }
            .cloudari-color-chip {
                width: 16px;
                height: 16px;
                border-radius: 999px;
                border: 1px solid #ccd0d4;
                background:
                    linear-gradient(45deg, #f1f1f1 25%, transparent 25%, transparent 75%, #f1f1f1 75%, #f1f1f1),
                    linear-gradient(45deg, #f1f1f1 25%, transparent 25%, transparent 75%, #f1f1f1 75%, #f1f1f1);
                background-position: 0 0, 4px 4px;
                background-size: 8px 8px;
                position: relative;
                overflow: hidden;
            }
            .cloudari-color-chip::after {
                content: "";
                position: absolute;
                inset: 0;
                background: var(--cloudari-chip-color, transparent);
            }
        </style>
        <?php
    }
}
