<?php
namespace Cloudari\Onebox\Domain\Theatre;

final class TheatreProfile
{
    public string $slug;
    public string $label;
    public string $venueName;

    public string $colorPrimary;
    public string $colorAccent;
    public string $colorBackground;
    public string $colorText;
    public string $colorSelectedDay;

    /** @var array<string, OneboxIntegration> */
    public array $integrations = [];

    public string $defaultIntegrationSlug;

    public function __construct(
        string $slug,
        string $label,
        string $venueName,
        string $colorPrimary,
        string $colorAccent,
        string $colorBackground,
        string $colorText,
        string $colorSelectedDay,
        array $integrations,
        string $defaultIntegrationSlug
    ) {
        $this->slug              = $slug;
        $this->label             = $label;
        $this->venueName         = $venueName;
        $this->colorPrimary      = $colorPrimary;
        $this->colorAccent       = $colorAccent;
        $this->colorBackground   = $colorBackground;
        $this->colorText         = $colorText;
        $this->colorSelectedDay  = $colorSelectedDay;

        $this->integrations = self::normalizeIntegrations($integrations);
        $this->defaultIntegrationSlug = self::resolveDefaultIntegrationSlug(
            $defaultIntegrationSlug,
            $this->integrations
        );
    }

    public static function fromArray(array $data): self
    {
        $integrations = [];

        if (isset($data['integrations']) && is_array($data['integrations'])) {
            foreach ($data['integrations'] as $key => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $slug = isset($row['slug']) ? sanitize_key((string)$row['slug']) : '';
                if ($slug === '') {
                    $slug = sanitize_key((string)$key);
                }
                if ($slug === '') {
                    $slug = 'integration';
                }
                $integrations[$slug] = OneboxIntegration::fromArray($row, $slug);
            }
        }

        if (empty($integrations)) {
            $legacy = [
                'slug'            => 'default',
                'label'           => 'OneBox',
                'channel_id'      => $data['channel_id'] ?? '',
                'client_secret'   => $data['client_secret'] ?? '',
                'api_catalog_url' => $data['api_catalog_url'] ?? 'https://api.oneboxtds.com/catalog-api/v1',
                'api_auth_url'    => $data['api_auth_url'] ?? 'https://oauth2.oneboxtds.com/oauth/token',
                'purchase_base'   => $data['purchase_base'] ?? '',
            ];
            $integrations['default'] = OneboxIntegration::fromArray($legacy, 'default');
        }

        return new self(
            $data['slug']            ?? 'default',
            $data['label']           ?? 'Perfil por defecto',
            $data['venue_name']      ?? 'La Estacion',
            $data['color_primary']   ?? '#009AD8',
            $data['color_accent']    ?? '#D14100',
            $data['color_bg']        ?? '#FFFFFF',
            $data['color_text']      ?? '#000000',
            $data['color_selected_day']
                ?? ($data['color_primary'] ?? '#009AD8'),
            $integrations,
            (string)($data['default_integration'] ?? '')
        );
    }

    public function toArray(): array
    {
        $integrations = [];
        foreach ($this->integrations as $slug => $integration) {
            if ($integration instanceof OneboxIntegration) {
                $integrations[$slug] = $integration->toArray();
            }
        }

        return [
            'slug'                 => $this->slug,
            'label'                => $this->label,
            'venue_name'           => $this->venueName,
            'color_primary'        => $this->colorPrimary,
            'color_accent'         => $this->colorAccent,
            'color_bg'             => $this->colorBackground,
            'color_text'           => $this->colorText,
            'color_selected_day'   => $this->colorSelectedDay,
            'default_integration'  => $this->defaultIntegrationSlug,
            'integrations'         => $integrations,
        ];
    }

    public function hasCredentials(): bool
    {
        foreach ($this->integrations as $integration) {
            if ($integration instanceof OneboxIntegration && $integration->hasCredentials()) {
                return true;
            }
        }
        return false;
    }

    public function getIntegrations(): array
    {
        return $this->integrations;
    }

    public function getIntegration(string $slug): ?OneboxIntegration
    {
        return $this->integrations[$slug] ?? null;
    }

    public function getDefaultIntegration(): ?OneboxIntegration
    {
        return $this->integrations[$this->defaultIntegrationSlug]
            ?? (count($this->integrations) ? reset($this->integrations) : null);
    }

    private static function normalizeIntegrations(array $integrations): array
    {
        $out = [];
        foreach ($integrations as $key => $integration) {
            if ($integration instanceof OneboxIntegration) {
                $slug = $integration->slug !== '' ? $integration->slug : sanitize_key((string)$key);
                if ($slug === '') {
                    $slug = 'integration';
                }
                $out[$slug] = $integration;
                continue;
            }
            if (is_array($integration)) {
                $slug = isset($integration['slug']) ? sanitize_key((string)$integration['slug']) : '';
                if ($slug === '') {
                    $slug = sanitize_key((string)$key);
                }
                if ($slug === '') {
                    $slug = 'integration';
                }
                $out[$slug] = OneboxIntegration::fromArray($integration, $slug);
            }
        }

        if (empty($out)) {
            $out['default'] = OneboxIntegration::fromArray([], 'default');
        }

        return $out;
    }

    private static function resolveDefaultIntegrationSlug(string $slug, array $integrations): string
    {
        $slug = sanitize_key($slug);
        if ($slug !== '' && isset($integrations[$slug])) {
            return $slug;
        }
        return (string)array_key_first($integrations);
    }
}
