<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations\Maintenance;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Texts\Text;

/**
 * One stored text of a UI text key as the database holds it (a projection of an AppTranslationValue row, never an
 * object of the entity registry).
 */
class AppTranslationStoredText extends ValueObject
{
    /** @var int The id of the value row */
    public int $appTranslationValueId = 0;

    /** @var int */
    public int $appTranslationKeyId = 0;

    /** @var int */
    public int $languageId = 0;

    /** @var int|null Set for a text of one country only */
    public ?int $countryId = null;

    /** @var string Text::WRITING_STYLE_* */
    public string $writingStyle = Text::WRITING_STYLE_INFORMAL;

    /** @var string Text::CONTEXT_* */
    public string $context = Text::CONTEXT_ONE;

    /** @var string */
    public string $translation = '';

    /**
     * @return bool Whether the text holds for every country of its language
     */
    public function isCountryNeutral(): bool
    {
        return !$this->countryId;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->appTranslationValueId);
    }
}
