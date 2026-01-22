<?php
namespace Cloudari\Onebox\Domain\Onebox;

final class OneboxIntegration
{
    public string $slug;
    public string $label;
    public string $channelId;
    public string $clientSecret;
    public string $apiCatalogUrl;
    public string $apiAuthUrl;
    public string $purchaseBaseUrl;
    public bool $enabled;

    public function __construct(
        string $slug,
        string $label,
        string $channelId,
        string $clientSecret,
        string $apiCatalogUrl,
        string $apiAuthUrl,
        string $purchaseBaseUrl,
        bool $enabled
    ) {
        $this->slug = $slug;
        $this->label = $label;
        $this->channelId = $channelId;
        $this->clientSecret = $clientSecret;
        $this->apiCatalogUrl = rtrim($apiCatalogUrl, '/');
        $this->apiAuthUrl = rtrim($apiAuthUrl, '/');

        $purchaseBaseUrl = trim($purchaseBaseUrl);
        if ($purchaseBaseUrl === '' || $purchaseBaseUrl === '/') {
            $this->purchaseBaseUrl = '';
        } else {
            $this->purchaseBaseUrl = rtrim($purchaseBaseUrl, '/') . '/';
        }

        $this->enabled = $enabled;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['slug'] ?? 'default',
            $data['label'] ?? 'Integracion OneBox',
            $data['channel_id'] ?? '',
            $data['client_secret'] ?? '',
            $data['api_catalog_url'] ?? 'https://api.oneboxtds.com/catalog-api/v1',
            $data['api_auth_url'] ?? 'https://oauth2.oneboxtds.com/oauth/token',
            $data['purchase_base'] ?? '',
            (bool)($data['enabled'] ?? false)
        );
    }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label,
            'channel_id' => $this->channelId,
            'client_secret' => $this->clientSecret,
            'api_catalog_url' => $this->apiCatalogUrl,
            'api_auth_url' => $this->apiAuthUrl,
            'purchase_base' => $this->purchaseBaseUrl,
            'enabled' => $this->enabled,
        ];
    }

    public function hasCredentials(): bool
    {
        return $this->channelId !== '' && $this->clientSecret !== '';
    }
}
