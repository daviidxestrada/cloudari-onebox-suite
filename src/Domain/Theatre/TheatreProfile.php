<?php
namespace Cloudari\Onebox\Domain\Theatre;

final class TheatreProfile
{
    public string $slug;
    public string $label;

    // OneBox API
    public string $channelId;
    public string $clientSecret;
    public string $apiCatalogUrl;
    public string $apiAuthUrl;

    // Entradas / front
    public string $purchaseBaseUrl;  // https://tickets.oneboxtds.com/laestacion/events/
    public string $venueName;        // “La Estación” (para eventos manuales)

    // Colores básicos (luego podemos ampliarlos)
    public string $colorPrimary;     // Azul principal
    public string $colorAccent;      // CTA (botones)
    public string $colorBackground;  // Fondo widgets
    public string $colorText;        // Texto principal

    public function __construct(
        string $slug,
        string $label,
        string $channelId,
        string $clientSecret,
        string $apiCatalogUrl,
        string $apiAuthUrl,
        string $purchaseBaseUrl,
        string $venueName,
        string $colorPrimary,
        string $colorAccent,
        string $colorBackground,
        string $colorText,
        string $colorSelectedDay 

    ) {
        $this->slug            = $slug;
        $this->label           = $label;
        $this->channelId       = $channelId;
        $this->clientSecret    = $clientSecret;
        $this->apiCatalogUrl   = rtrim($apiCatalogUrl, '/');
        $this->apiAuthUrl      = rtrim($apiAuthUrl, '/');

        // Normalizar purchaseBaseUrl: si está vacío o es "/", no lo convertimos en "/"
        $purchaseBaseUrl = trim($purchaseBaseUrl);

        if ($purchaseBaseUrl === '' || $purchaseBaseUrl === '/') {
            // Valor vacío → dejarlo en blanco para que la capa de JS pueda usar su fallback
            $this->purchaseBaseUrl = '';
        } else {
            $this->purchaseBaseUrl = rtrim($purchaseBaseUrl, '/') . '/';
        }

        $this->venueName       = $venueName;
        $this->colorPrimary    = $colorPrimary;
        $this->colorAccent     = $colorAccent;
        $this->colorBackground = $colorBackground;
        $this->colorText       = $colorText;
        $this->colorSelectedDay = $colorSelectedDay;
    }

    public static function fromArray(array $data): self
{
    $rawBase = $data['purchase_base'] ?? '';
    if ($rawBase === '/' || trim($rawBase) === '') {
        $rawBase = '';
    }

    return new self(
        $data['slug']            ?? 'default',
        $data['label']           ?? 'Perfil por defecto',
        $data['channel_id']      ?? '',
        $data['client_secret']   ?? '',
        $data['api_catalog_url'] ?? 'https://api.oneboxtds.com/catalog-api/v1',
        $data['api_auth_url']    ?? 'https://oauth2.oneboxtds.com/oauth/token',
        $rawBase,
        $data['venue_name']      ?? 'La Estación',
        $data['color_primary']   ?? '#009AD8',
        $data['color_accent']    ?? '#D14100',
        $data['color_bg']        ?? '#FFFFFF',
        $data['color_text']      ?? '#000000',
        $data['color_selected_day']
            ?? ($data['color_primary'] ?? '#009AD8')   
    );
}


    public function toArray(): array
    {
        return [
            'slug'            => $this->slug,
            'label'           => $this->label,
            'channel_id'      => $this->channelId,
            'client_secret'   => $this->clientSecret,
            'api_catalog_url' => $this->apiCatalogUrl,
            'api_auth_url'    => $this->apiAuthUrl,
            'purchase_base'   => $this->purchaseBaseUrl,
            'venue_name'      => $this->venueName,
            'color_primary'   => $this->colorPrimary,
            'color_accent'    => $this->colorAccent,
            'color_bg'        => $this->colorBackground,
            'color_text'      => $this->colorText,
            'color_selected_day'=> $this->colorSelectedDay,
        ];
    }

    public function hasCredentials(): bool
    {
        return $this->channelId !== '' && $this->clientSecret !== '';
    }
}
