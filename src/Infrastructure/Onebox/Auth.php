<?php
namespace Cloudari\Onebox\Infrastructure\Onebox;

use Cloudari\Onebox\Domain\Onebox\OneboxIntegrationRepository;

final class Auth
{
    private const TRANSIENT_JWT     = 'cloudari_onebox_jwt_token';
    private const TRANSIENT_REFRESH = 'cloudari_onebox_refresh_token';
    private const TRANSIENT_ACTIVE  = 'cloudari_onebox_active_integration_cache';

    /**
     * Devuelve el JWT, usando caché (transient) y el perfil activo
     */
    public static function getJwt(): ?string
    {
        // 1) Miramos la caché primero
        $cached = get_transient(self::TRANSIENT_JWT);
        if ($cached !== false) {
            return $cached;
        }

        // 2) Sacamos la integracion activa
        $profile = OneboxIntegrationRepository::getActive();

        // Si cambia la integracion activa, reiniciamos tokens
        $cachedActive = get_transient(self::TRANSIENT_ACTIVE);
        if ($cachedActive !== $profile->slug) {
            self::resetTokens();
            set_transient(self::TRANSIENT_ACTIVE, $profile->slug, DAY_IN_SECONDS);
        }

        if (!$profile->hasCredentials()) {
            // Falta channel o client_secret
            return null;
        }

        // 3) Pedimos token nuevo
        $token = self::requestJwt($profile);
        if (!$token) {
            return null;
        }

        // 4) Analizamos el JWT para ver cuándo expira
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $payload = json_decode(
                base64_decode(strtr($parts[1], '-_', '+/')),
                true
            );

            $expiration = isset($payload['exp'])
                ? ($payload['exp'] - time() - 300) // 5 min de margen
                : 3600;

            $expiration = max(60, (int) $expiration);
            set_transient(self::TRANSIENT_JWT, $token, $expiration);
        } else {
            // Si el token no tiene el formato esperado, 1h
            set_transient(self::TRANSIENT_JWT, $token, 3600);
        }

        return $token;
    }

    /**
     * Llama al endpoint de Auth de OneBox usando el perfil
     */
    private static function requestJwt($profile): ?string
    {
        $url = $profile->apiAuthUrl;

        $postData = [
            'grant_type'    => 'client_credentials',
            'channel_id'    => $profile->channelId,
            'client_id'     => 'seller-channel-client',
            'client_secret' => $profile->clientSecret,
        ];

        $response = wp_remote_post($url, [
            'body'    => $postData,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Guardamos refresh_token por si en el futuro queremos usarlo
        if (isset($body['refresh_token'])) {
            set_transient(self::TRANSIENT_REFRESH, $body['refresh_token'], 30 * DAY_IN_SECONDS);
        }

        return $body['access_token'] ?? null;
    }

    /**
     * Borra los transients de token para forzar nuevo login con las credenciales actuales.
     * Llamar a esto cuando se cambien Channel ID / Client Secret en el panel.
     */
    public static function resetTokens(): void
    {
        delete_transient(self::TRANSIENT_JWT);
        delete_transient(self::TRANSIENT_REFRESH);
    }
}
