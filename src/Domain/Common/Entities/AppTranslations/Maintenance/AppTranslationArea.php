<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations\Maintenance;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Languages\Language;
use DDD\Domain\Common\Entities\Texts\Text;

/**
 * One place UI text keys are shown in (the app, a web, the server's own texts). No column stores the area: the start
 * of the key string does. The areas are configured ({@see AppTranslationAreas::fromConfig()}); one area without a key
 * prefix holds every key no other area claims.
 */
class AppTranslationArea extends ValueObject
{
    /** @var string The name of the area as tools and lists show it, e.g. APP, WEB, SERVER */
    public string $name;

    /** @var string What the area is, e.g. "the mobile app" */
    public string $label = '';

    /** @var string|null The start of every key of the area, e.g. `mobile.`; null: every key no other area claims */
    public ?string $keyPrefix = null;

    /** @var bool Whether the keys and their English texts come from the code (a sync writes them; never created by hand) */
    public bool $isSyncedFromCode = false;

    /** @var string[] The writing styles the area keeps (Text::WRITING_STYLE_*); empty: those the language is written in */
    public array $writingStyles = [];

    /** @var string|null When a changed text is shown, e.g. "after the next start of the app" */
    public ?string $liveNote = null;

    public function __construct(string $name = '')
    {
        $this->name = $name;
        parent::__construct();
    }

    /**
     * @param string $key
     * @return bool Whether the key string starts with the area's prefix (always false for the area without a prefix)
     */
    public function hasPrefixOfKey(string $key): bool
    {
        return $this->keyPrefix !== null && $this->keyPrefix !== '' && str_starts_with($key, $this->keyPrefix);
    }

    /**
     * The writing styles a text of the language is kept in for this area: the area's own, else what the language is
     * written in.
     *
     * @param Language $language
     * @return string[] Text::WRITING_STYLE_*
     */
    public function getWritingStylesOfLanguage(Language $language): array
    {
        if ($this->writingStyles !== []) {
            return $this->writingStyles;
        }
        return match ($language->supportedWritingStyles ?? Language::WRITING_STYLE_INFORMAL) {
            Language::WRITING_STYLE_FORMAL => [Text::WRITING_STYLE_FORMAL],
            Language::WRITING_STYLE_FORMAL_AND_INFORMAL => [Text::WRITING_STYLE_FORMAL, Text::WRITING_STYLE_INFORMAL],
            default => [Text::WRITING_STYLE_INFORMAL],
        };
    }

    /**
     * Example: `APP (the mobile app, keys mobile.*)` · `SERVER (mails, push and error texts)`
     *
     * @return string
     */
    public function getDescription(): string
    {
        $details = array_filter([
            $this->label,
            $this->keyPrefix !== null && $this->keyPrefix !== '' ? 'keys ' . $this->keyPrefix . '*' : null,
        ]);
        return $this->name . ($details !== [] ? ' (' . implode(', ', $details) . ')' : '');
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }
}
