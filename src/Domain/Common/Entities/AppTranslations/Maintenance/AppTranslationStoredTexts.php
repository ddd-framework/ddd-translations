<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations\Maintenance;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Stored texts of UI text keys, ordered by key, language, writing style.
 *
 * @property AppTranslationStoredText[] $elements
 * @method AppTranslationStoredText|null getByUniqueKey(string $uniqueKey)
 * @method AppTranslationStoredText[] getElements()
 * @method AppTranslationStoredText|null first()
 */
class AppTranslationStoredTexts extends ObjectSet
{
    /**
     * @param int $appTranslationKeyId
     * @return static The texts of one key
     */
    public function filterByKeyId(int $appTranslationKeyId): static
    {
        $storedTextsOfKey = new static();
        foreach ($this->getElements() as $appTranslationStoredText) {
            if ($appTranslationStoredText->appTranslationKeyId === $appTranslationKeyId) {
                $storedTextsOfKey->add($appTranslationStoredText);
            }
        }
        return $storedTextsOfKey;
    }
}
