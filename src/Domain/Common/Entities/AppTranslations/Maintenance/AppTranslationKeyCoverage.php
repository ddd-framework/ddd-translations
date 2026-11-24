<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations\Maintenance;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One UI text key with the languages that have a text for it and the active languages that have none: the row of a
 * key list.
 */
class AppTranslationKeyCoverage extends ValueObject
{
    /** @var int */
    public int $appTranslationKeyId = 0;

    /** @var string */
    public string $key = '';

    /** @var string The name of the key's area */
    public string $areaName = '';

    /** @var string[] Codes of the languages that have a text, sorted */
    public array $languageCodesWithText = [];

    /** @var string[] Codes of the active languages without a text, sorted */
    public array $missingLanguageCodes = [];

    /** @var string|null A stored text of the key: the one that matched the search, else the English or first one */
    public ?string $sampleText = null;

    /** @var string|null The language code of $sampleText */
    public ?string $sampleLanguageCode = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->appTranslationKeyId);
    }
}
