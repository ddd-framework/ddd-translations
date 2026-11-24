<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations\Maintenance;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a list of UI text keys is narrowed by ({@see \DDD\Domain\Common\Services\AppTranslations\AppTranslationsMaintenanceService::findKeyCoverages()}).
 * Every criterion is optional; all given ones have to hold.
 */
class AppTranslationKeyListCriteria extends ValueObject
{
    /** @var string|null Words of the key or of one of its texts (case-insensitive part match) */
    public ?string $searchText = null;

    /** @var int|null With $searchText: only texts of this language are searched (the key itself is still searched) */
    public ?int $searchLanguageId = null;

    /** @var string|null Only keys of this area (AppTranslationArea::$name) */
    public ?string $areaName = null;

    /** @var int|null Only keys that have no text in this language */
    public ?int $missingLanguageId = null;
}
