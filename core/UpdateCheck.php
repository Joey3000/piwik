<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik;

use Exception;
use Piwik\Plugins\SitesManager\API;

/**
 * Class to check if a newer version of Piwik is available
 *
 */
class UpdateCheck
{
    const CHECK_INTERVAL = 28800; // every 8 hours
    const UI_CLICK_CHECK_INTERVAL = 10; // every 10s when user clicks UI link
    const LAST_TIME_CHECKED = 'UpdateCheck_LastTimeChecked';
    const LATEST_VERSION = 'UpdateCheck_LatestVersion';
    const SOCKET_TIMEOUT = 2;

    const RELEASE_CHANNEL_LATEST_STABLE = 'latest_stable';
    const RELEASE_CHANNEL_LATEST_BETA   = 'latest_beta';
    const RELEASE_CHANNEL_2X_STABLE     = '2x_stable';
    const RELEASE_CHANNEL_2X_BETA       = '2x_beta';
    const RELEASE_CHANNEL_DEFAULT       = self::RELEASE_CHANNEL_LATEST_STABLE;

    const LATEST_VERSION_URL = '://builds.piwik.org/piwik.zip';
    const LATEST_BETA_VERSION_URL = '://builds.piwik.org/piwik-%s.zip';
    const LATEST_2X_VERSION_URL = '://builds.piwik.org/piwik2x.zip';
    const LATEST_2X_BETA_VERSION_URL = '://builds.piwik.org/piwik2x-%s.zip';

    private static function isAutoUpdateEnabled()
    {
        return (bool) Config::getInstance()->General['enable_auto_update'];
    }

    private static function getReleaseChannel()
    {
        $channel = @Config::getInstance()->General['release_channel'];

        if (!self::isValidReleaseChannel($channel)) {
            return self::RELEASE_CHANNEL_DEFAULT;
        }

        return $channel;
    }

    public static function getPiwikArchiveUrlForCurrentReleaseChannel($version)
    {
        $channel = self::getReleaseChannel();

        switch ($channel) {
            case self::RELEASE_CHANNEL_LATEST_BETA:
                return sprintf(self::LATEST_BETA_VERSION_URL, $version);

            case self::RELEASE_CHANNEL_2X_STABLE:
                return self::LATEST_2X_VERSION_URL;

            case self::RELEASE_CHANNEL_2X_BETA:
                return sprintf(self::LATEST_2X_BETA_VERSION_URL, $version);

            case self::RELEASE_CHANNEL_LATEST_STABLE:
            default:
                return self::LATEST_VERSION_URL;

        }
    }

    public static function isValidReleaseChannel($releaseChannel)
    {
        return in_array($releaseChannel, array(
            self::RELEASE_CHANNEL_LATEST_STABLE,
            self::RELEASE_CHANNEL_LATEST_BETA,
            self::RELEASE_CHANNEL_2X_STABLE,
            self::RELEASE_CHANNEL_2X_BETA,
        ));
    }

    /**
     * Check for a newer version
     *
     * @param bool $force Force check
     * @param int $interval Interval used for update checks
     */
    public static function check($force = false, $interval = null)
    {
        if (!self::isAutoUpdateEnabled()) {
            return;
        }

        if ($interval === null) {
            $interval = self::CHECK_INTERVAL;
        }

        $lastTimeChecked = Option::get(self::LAST_TIME_CHECKED);
        if ($force
            || $lastTimeChecked === false
            || time() - $interval > $lastTimeChecked
        ) {
            // set the time checked first, so that parallel Piwik requests don't all trigger the http requests
            Option::set(self::LAST_TIME_CHECKED, time(), $autoLoad = 1);

            $url = self::getUrlToCheckForLatestAvailableVersion();
            $timeout = self::SOCKET_TIMEOUT;

            try {
                $latestVersion = Http::sendHttpRequest($url, $timeout);
                if (!preg_match('~^[0-9][0-9a-zA-Z_.-]*$~D', $latestVersion)) {
                    $latestVersion = '';
                }
            } catch (Exception $e) {
                // e.g., disable_functions = fsockopen; allow_url_open = Off
                $latestVersion = '';
            }
            Option::set(self::LATEST_VERSION, $latestVersion);
        }
    }

    private static function getUrlToCheckForLatestAvailableVersion()
    {
        $releaseChannel = self::getReleaseChannel();

        $parameters = array(
            'piwik_version'   => Version::VERSION,
            'php_version'     => PHP_VERSION,
            'release_channel' => $releaseChannel,
            'url'             => Url::getCurrentUrlWithoutQueryString(),
            'trigger'         => Common::getRequestVar('module', '', 'string'),
            'timezone'        => API::getInstance()->getDefaultTimezone(),
        );

        $url = Config::getInstance()->General['api_service_url']
            . '/1.0/getLatestVersion/'
            . '?' . http_build_query($parameters, '', '&');

        switch ($releaseChannel) {
            case self::RELEASE_CHANNEL_2X_BETA:
                return 'http://builds.piwik.org/LATEST_2X_BETA';

            case self::RELEASE_CHANNEL_LATEST_BETA:
                return 'http://builds.piwik.org/LATEST_BETA';

            case self::RELEASE_CHANNEL_LATEST_STABLE:
            case self::RELEASE_CHANNEL_2X_STABLE:
            default:
                return $url;

        }
    }

    /**
     * Returns the latest available version number. Does not perform a check whether a later version is available.
     *
     * @return false|string
     */
    public static function getLatestVersion()
    {
        return Option::get(self::LATEST_VERSION);
    }

    /**
     * Returns version number of a newer Piwik release.
     *
     * @return string|bool  false if current version is the latest available,
     *                       or the latest version number if a newest release is available
     */
    public static function isNewestVersionAvailable()
    {
        $latestVersion = self::getLatestVersion();
        if (!empty($latestVersion)
            && version_compare(Version::VERSION, $latestVersion) == -1
        ) {
            return $latestVersion;
        }
        return false;
    }
}
