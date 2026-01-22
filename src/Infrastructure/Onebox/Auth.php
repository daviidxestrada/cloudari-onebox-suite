<?php
namespace Cloudari\Onebox\Infrastructure\Onebox;

use Cloudari\Onebox\Domain\Theatre\OneboxIntegration;
use Cloudari\Onebox\Domain\Theatre\ProfileRepository;

final class Auth
{
    private const TRANSIENT_JWT_PREFIX     = 'cloudari_onebox_jwt_token_';
    private const TRANSIENT_REFRESH_PREFIX = 'cloudari_onebox_refresh_token_';

    public static function getJwt(OneboxIntegration $integration): ?string
    {
        $slug = sanitize_key($integration->slug) ?: 'default';
        $cacheKey = self::TRANSIENT_JWT_PREFIX . $slug;

        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        if (!$integration->hasCredentials()) {
            return null;
        }

        $token = self::requestJwt($integration);
        if (!$token) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $payload = json_decode(
                base64_decode(strtr($parts[1], '-_', '+/')),
                true
            );

            $expiration = isset($payload['exp'])
                ? ($payload['exp'] - time() - 300)
                : 3600;

            $expiration = max(60, (int) $expiration);
            set_transient($cacheKey, $token, $expiration);
        } else {
            set_transient($cacheKey, $token, 3600);
        }

        return $token;
    }

    private static function requestJwt(OneboxIntegration $integration): ?string
    {
        $url = $integration->apiAuthUrl;

        $postData = [
            'grant_type'    => 'client_credentials',
            'channel_id'    => $integration->channelId,
            'client_id'     => 'seller-channel-client',
            'client_secret' => $integration->clientSecret,
        ];

        $response = wp_remote_post($url, [
            'body'    => $postData,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['refresh_token'])) {
            $refreshKey = self::TRANSIENT_REFRESH_PREFIX . (sanitize_key($integration->slug) ?: 'default');
            set_transient($refreshKey, $body['refresh_token'], 30 * DAY_IN_SECONDS);
        }

        return $body['access_token'] ?? null;
    }

    public static function resetTokens(?string $slug = null): void
    {
        if ($slug !== null) {
            $slug = sanitize_key($slug) ?: 'default';
            delete_transient(self::TRANSIENT_JWT_PREFIX . $slug);
            delete_transient(self::TRANSIENT_REFRESH_PREFIX . $slug);
            return;
        }

        $profile = ProfileRepository::getActive();
        foreach ($profile->getIntegrations() as $integration) {
            if (!$integration instanceof OneboxIntegration) {
                continue;
            }
            $key = sanitize_key($integration->slug) ?: 'default';
            delete_transient(self::TRANSIENT_JWT_PREFIX . $key);
            delete_transient(self::TRANSIENT_REFRESH_PREFIX . $key);
        }
    }
}
