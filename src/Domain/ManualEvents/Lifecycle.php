<?php
namespace Cloudari\Onebox\Domain\ManualEvents;

use Cloudari\Onebox\Rest\Routes;

if (!defined('ABSPATH')) {
    exit;
}

final class Lifecycle
{
    private const VERSION_OPTION = 'cloudari_onebox_last_maintenance_version';

    public static function register(): void
    {
        add_action('before_delete_post', [self::class, 'clearCachesForManualPost'], 10, 1);
        add_action('deleted_post', [self::class, 'clearCachesForManualPost'], 10, 1);
        add_action('trashed_post', [self::class, 'clearCachesForManualPost'], 10, 1);
        add_action('untrashed_post', [self::class, 'clearCachesForManualPost'], 10, 1);
        add_action('transition_post_status', [self::class, 'clearCachesOnStatusChange'], 10, 3);
        add_action('init', [self::class, 'runUpgradeMaintenance'], 30);
    }

    public static function clearCachesForManualPost(int $postId): void
    {
        if (!self::isManualEventPost($postId)) {
            return;
        }

        Routes::clearBillboardCache();
    }

    public static function clearCachesOnStatusChange(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($newStatus === $oldStatus || $post->post_type !== PostType::SLUG) {
            return;
        }

        Routes::clearBillboardCache();
    }

    public static function runUpgradeMaintenance(): void
    {
        $currentVersion = defined('CLOUDARI_ONEBOX_VER') ? (string) CLOUDARI_ONEBOX_VER : '';
        if ($currentVersion === '') {
            return;
        }

        $storedVersion = (string) get_option(self::VERSION_OPTION, '');
        if ($storedVersion === $currentVersion) {
            return;
        }

        Routes::clearBillboardCache();
        update_option(self::VERSION_OPTION, $currentVersion, false);
    }

    private static function isManualEventPost(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        return get_post_type($postId) === PostType::SLUG;
    }
}
