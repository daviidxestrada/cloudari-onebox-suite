<?php
namespace Cloudari\Onebox\Domain\Theatre;

final class OneboxIntegration
{
    public string $slug;
    public string $label;
    public string $channelId;
    public string $clientSecret;
    public string $apiCatalogUrl;
    public string $apiAuthUrl;
    public string $purchaseBaseUrl;

    public function __construct(
        string $slug,
        string $label,
        string $channelId,
        string $clientSecret,
        string $apiCatalogUrl,
        string $apiAuthUrl,
        string $purchaseBaseUrl
    ) {
        $this->slug         = $slug;
        $this->label        = $label;
        $this->channelId    = $channelId;
        $this->clientSecret = $clientSecret;
        $this->apiCatalogUrl = rtrim($apiCatalogUrl, '/');
        $this->apiAuthUrl    = rtrim($apiAuthUrl, '/');

        $purchaseBaseUrl = trim($purchaseBaseUrl);
        if ($purchaseBaseUrl === '' || $purchaseBaseUrl === '/') {
            $this->purchaseBaseUrl = '';
        } else {
            $this->purchaseBaseUrl = rtrim($purchaseBaseUrl, '/') . '/';
        }
    }

    public static function fromArray(array $data, string $fallbackSlug): self
    {
        $slug = isset($data['slug']) ? sanitize_key((string)$data['slug']) : '';
        if ($slug === '') {
            $slug = sanitize_key($fallbackSlug);
        }
        if ($slug === '') {
            $slug = 'integration';
        }

        $rawBase = $data['purchase_base'] ?? '';
        if ($rawBase === '/' || trim((string)$rawBase) === '') {
            $rawBase = '';
        }

        return new self(
            $slug,
            (string)($data['label'] ?? 'OneBox'),
            (string)($data['channel_id'] ?? ''),
            (string)($data['client_secret'] ?? ''),
            (string)($data['api_catalog_url'] ?? 'https://api.oneboxtds.com/catalog-api/v1'),
            (string)($data['api_auth_url'] ?? 'https://oauth2.oneboxtds.com/oauth/token'),
            (string)$rawBase
        );
    }

    public function toArray(): array
    {
        return [
            'slug'           => $this->slug,
            'label'          => $this->label,
            'channel_id'     => $this->channelId,
            'client_secret'  => $this->clientSecret,
            'api_catalog_url'=> $this->apiCatalogUrl,
            'api_auth_url'   => $this->apiAuthUrl,
            'purchase_base'  => $this->purchaseBaseUrl,
        ];
    }

    public function hasCredentials(): bool
    {
        return $this->channelId !== '' && $this->clientSecret !== '';
    }
}
