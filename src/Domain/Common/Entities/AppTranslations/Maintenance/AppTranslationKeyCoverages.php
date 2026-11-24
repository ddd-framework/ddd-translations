<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations\Maintenance;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * One page of UI text keys with their languages, ordered by key.
 *
 * @property AppTranslationKeyCoverage[] $elements
 * @method AppTranslationKeyCoverage|null getByUniqueKey(string $uniqueKey)
 * @method AppTranslationKeyCoverage[] getElements()
 * @method AppTranslationKeyCoverage|null first()
 */
class AppTranslationKeyCoverages extends ObjectSet
{
}
