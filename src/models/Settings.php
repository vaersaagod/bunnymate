<?php

namespace vaersaagod\bunnymate\models;

use craft\base\Model;

/**
 * BunnyMate settings model
 *
 * @author Værsågod
 * @since 2.0.0
 */
class Settings extends Model
{

    // Public Properties
    // =========================================================================

    /** @var bool Whether pull zone URLs should be used at all */
    public bool $pullingEnabled = true;

    /** @var array[] Pull zone configs, indexed by handle */
    public array $pullZones = [];

    /** @var string|null The handle of the pull zone to use by default */
    public ?string $defaultPullZone = null;

    /**
     * @var string|null Bunny account API key, used to purge the CDN cache.
     *
     * This is the account-wide API key from the Bunny dashboard, *not* a storage zone password.
     * Can be set to an environment variable, e.g. `$BUNNY_API_KEY`.
     *
     * @since 2.1.0
     */
    public ?string $apiKey = null;

    /**
     * @var bool Whether changed files should be purged from the CDN cache when assets are
     * added, replaced, moved or deleted via a Bunny Storage filesystem.
     *
     * Individual URLs are purged. The pull zone is never purged as a whole.
     * @since 2.1.0
     */
    public bool $purgeEnabled = true;

}
