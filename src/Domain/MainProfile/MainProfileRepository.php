<?php
namespace Cloudari\Onebox\Domain\MainProfile;

final class MainProfileRepository
{
    private const OPTION_MAIN_PROFILE = 'cloudari_onebox_main_profile';

    public static function get(): MainProfile
    {
        $raw = get_option(self::OPTION_MAIN_PROFILE, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        if ($raw === []) {
            $profile = MainProfile::fromArray([]);
            self::save($profile);
            return $profile;
        }

        return MainProfile::fromArray($raw);
    }

    public static function save(MainProfile $profile): void
    {
        update_option(self::OPTION_MAIN_PROFILE, $profile->toArray());
    }
}
