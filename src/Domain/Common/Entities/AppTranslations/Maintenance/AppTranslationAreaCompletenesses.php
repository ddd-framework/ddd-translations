<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations\Maintenance;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Per area, active language and writing style: how many keys have a text. Ordered by area, language code, style.
 *
 * @property AppTranslationAreaCompleteness[] $elements
 * @method AppTranslationAreaCompleteness|null getByUniqueKey(string $uniqueKey)
 * @method AppTranslationAreaCompleteness[] getElements()
 * @method AppTranslationAreaCompleteness|null first()
 */
class AppTranslationAreaCompletenesses extends ObjectSet
{
}
