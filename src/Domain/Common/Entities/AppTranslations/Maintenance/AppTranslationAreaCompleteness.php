<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations\Maintenance;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * How many keys of one area have a text in one language and writing style.
 */
class AppTranslationAreaCompleteness extends ValueObject
{
    /** @var string The name of the area */
    public string $areaName = '';

    /** @var string */
    public string $languageCode = '';

    /** @var string Text::WRITING_STYLE_* */
    public string $writingStyle = '';

    /** @var int Keys of the area */
    public int $keyCount = 0;

    /** @var int Keys of the area with a text in the language and style */
    public int $translatedKeyCount = 0;

    /**
     * @return int Keys of the area without a text in the language and style
     */
    public function getMissingKeyCount(): int
    {
        return max(0, $this->keyCount - $this->translatedKeyCount);
    }

    /**
     * Examples: `de INFORMAL 1,204 of 1,210 (6 missing)` · `fr FORMAL complete (310)`
     *
     * @return string
     */
    public function getSummary(): string
    {
        $head = $this->languageCode . ' ' . $this->writingStyle . ' ';
        if ($this->keyCount > 0 && $this->getMissingKeyCount() === 0) {
            return $head . 'complete (' . number_format($this->keyCount) . ')';
        }
        return $head . number_format($this->translatedKeyCount) . ' of ' . number_format($this->keyCount)
            . ' (' . number_format($this->getMissingKeyCount()) . ' missing)';
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->areaName . '_' . $this->languageCode . '_' . $this->writingStyle);
    }
}
