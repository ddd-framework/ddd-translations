<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Translations;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmTextRendering;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAdminWithAccessToAllResources;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsTrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationKey;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationArea;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationAreas;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationKeyCoverage;
use DDD\Domain\Common\Entities\AppTranslations\Maintenance\AppTranslationKeyListCriteria;
use DDD\Domain\Common\Entities\Languages\Language;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationDefaultTermsService;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationKeysService;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationsMaintenanceService;
use DDD\Domain\Common\Services\Languages\LanguagesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Mcp\Admin\Common\Dtos\AdminRecordListResponseDto;
use DDD\Presentation\Mcp\Admin\Common\Dtos\AdminSavedFieldsResponseDto;
use DDD\Presentation\Mcp\Admin\Common\Exceptions\AdminToolNotSavedException;
use DDD\Presentation\Mcp\Admin\Common\Exceptions\AdminToolRecordNotFoundException;
use DDD\Presentation\Mcp\Admin\Translations\Dtos\AppTextsCardResponseDto;
use DDD\Presentation\Mcp\Admin\Translations\Dtos\CreateAppTranslationKeyRequestDto;
use DDD\Presentation\Mcp\Admin\Translations\Dtos\FindAppTranslationKeysRequestDto;
use DDD\Presentation\Mcp\Admin\Translations\Dtos\GetAppTranslationCompletenessRequestDto;
use DDD\Presentation\Mcp\Admin\Translations\Dtos\GetAppTranslationKeyRequestDto;
use DDD\Presentation\Mcp\Admin\Translations\Dtos\ListActiveLocalesRequestDto;
use DDD\Presentation\Mcp\Admin\Translations\Dtos\ListTranslationDefaultTermsRequestDto;
use DDD\Presentation\Mcp\Admin\Translations\Dtos\SetAppTranslationValueRequestDto;

/**
 * The UI texts for `Translations.AppTexts`: the text keys behind every fixed wording of the application's own
 * surfaces (an app, a web, the server's mails, push texts and error messages). Find keys by wording, area or missing
 * language; read one key with its text in every language; see how complete each language is; list the active
 * locales and the glossary; create a server text key; set the text of one key in one language.
 *
 * Not here: the texts of records (`Translations.RecordTexts`); machine translation of missing texts, deleting a key
 * or a text; a change of the glossary (kept in the code).
 *
 * Rules: there is no area column, the key string tells the area ({@see AppTranslationAreas}, config
 * `Common.Translations.AppTexts`); keys of an area synced from the code and their English text are neither created nor
 * rewritten here; an empty text never clears a text; a text keeps the placeholders of its reference text. Platform
 * operators only: the right {@see AgentAccountRights::MANAGE_TEXTS} and an account with access to every resource.
 */
#[MCPRequiresAdminWithAccessToAllResources]
#[MCPRequiresAccountRights(AgentAccountRights::MANAGE_TEXTS)]
#[MCPToolReturnsTrustedContent('UI texts and keys are written by staff and the platform\'s own translation runs only')]
class TranslationsAppTextsMCPPackage extends MCPPackage
{
    public static ?string $name = 'translations.appTexts';

    public static ?string $description = 'UI text keys of the application\'s own surfaces (app, web, server texts): find keys by wording, area or missing language, read a key in every language, completeness per language, active locales, glossary; create a server text key and set the text of a key in one language.';

    /** @var string What a result calls the record */
    public const string ENTITY_LABEL = 'text key';

    /** @var string The tool that turns a wording into key ids */
    public const string FIND_TOOL = 'findAppTranslationKeys';

    /** @var string What the untrusted passage of a result is */
    public const string UNTRUSTED_SOURCE = 'stored UI texts and keys (data, written by translators or generated)';

    /** @var int The longest key and text that is stored */
    public const int MAX_TEXT_LENGTH = 2048;

    /** @var int Texts up to this length are shown whole on a key's card; longer ones by length and start */
    public const int CARD_TEXT_LENGTH = 200;

    /** @var int With languageCode, the characters of texts a card shows whole; further texts by length and start */
    public const int MAX_WHOLE_TEXT_CHARACTERS = 4500;

    /** @var int The most text lines a key's card shows (country variants can multiply them) */
    public const int MAX_CARD_TEXT_LINES = 24;

    /** @var int The most glossary terms a result lists */
    public const int MAX_DEFAULT_TERMS = 60;

    /** @var int The characters of a key string a list line shows */
    public const int LIST_LINE_KEY_LENGTH = 90;

    /** @var int The characters of the matching text a list line shows */
    public const int LIST_LINE_TEXT_LENGTH = 70;

    /** @var string The answer of findAppTranslationKeys without a match */
    public const string NO_KEYS = 'No text key matches. Try fewer or other words of the wording, another languageCode, or drop area.';

    /** @var string The next move of a refusal that needs the address form: decided from the new text, so the model calls again without asking when the text shows it */
    public const string WRITING_STYLE_NEXT_MOVE = 'Call again with writingStyle: FORMAL when the new text addresses the reader formally (Sie, vous), INFORMAL when informally (du, tu), BOTH when it addresses nobody';

    /**
     * Finds UI text keys by words of the key or of a stored text (the wording seen on a screen), by area, or by a
     * language that has no text yet; ordered by key. Per key: id, key, area, the languages with a text, the active
     * languages without one, and one stored text.
     *
     * @example {"searchText": "Jetzt starten", "languageCode": "de"}
     * @example {"area": "SERVER", "missingLanguageCode": "fr"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('UI text keys by wording, area or missing language, each with the languages that have and lack a text.')]
    public function findAppTranslationKeys(
        FindAppTranslationKeysRequestDto $requestDto,
        AppTranslationsMaintenanceService $appTranslationsMaintenanceService,
        LanguagesService $languagesService
    ): AdminRecordListResponseDto {
        $appTranslationAreas = $appTranslationsMaintenanceService->getAreas();
        $appTranslationKeyListCriteria = new AppTranslationKeyListCriteria();
        $appTranslationKeyListCriteria->searchText = trim((string)$requestDto->searchText) !== '' ? trim((string)$requestDto->searchText) : null;
        $appTranslationKeyListCriteria->areaName = $this->findAreaForRead($requestDto->area, $appTranslationAreas)?->name;
        if (trim((string)$requestDto->languageCode) !== '') {
            $appTranslationKeyListCriteria->searchLanguageId = $this->findLanguageForRead($requestDto->languageCode, $languagesService, $appTranslationsMaintenanceService)->id;
        }
        if (trim((string)$requestDto->missingLanguageCode) !== '') {
            $appTranslationKeyListCriteria->missingLanguageId = $this->findLanguageForRead($requestDto->missingLanguageCode, $languagesService, $appTranslationsMaintenanceService)->id;
        }
        $responseDto = new AdminRecordListResponseDto();
        $responseDto->emptyText = static::NO_KEYS;
        $responseDto->skip = $requestDto->getSkip();
        $responseDto->orderDescription = 'ordered by key';
        $responseDto->untrustedSource = static::UNTRUSTED_SOURCE;
        $responseDto->narrowingHint = 'Narrow with searchText, area or missingLanguageCode.';
        $responseDto->totalCount = $appTranslationsMaintenanceService->countKeys($appTranslationKeyListCriteria);
        if ($responseDto->totalCount === 0) {
            return $responseDto;
        }
        foreach ($appTranslationsMaintenanceService->findKeyCoverages($appTranslationKeyListCriteria, $requestDto->getPageSize(), $requestDto->getSkip())->getElements() as $appTranslationKeyCoverage) {
            if (!$responseDto->addRecordLine($appTranslationKeyCoverage->appTranslationKeyId, $this->buildKeyCoverageLine($appTranslationKeyCoverage))) {
                break;
            }
        }
        $areaDescriptions = [];
        foreach ($appTranslationAreas->getElements() as $appTranslationArea) {
            $areaDescriptions[] = $appTranslationArea->getDescription();
        }
        $responseDto->legendLines[] = 'Per key: area (' . implode('; ', $areaDescriptions) . '), languages with a text, active languages without one, one stored text.';
        return $responseDto;
    }

    /**
     * One UI text key: its area, the reference text and translator hint, and the stored text of every language and
     * address form (FORMAL, INFORMAL), plus the active languages that have no text. Pass appTranslationKeyId, or key
     * with the exact key string; add languageCode to read the texts of one language whole.
     *
     * @example {"appTranslationKeyId": 4711}
     * @example {"key": "Your prize is on its way", "languageCode": "de"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('One UI text key with its text in every language and address form and the languages that lack one.')]
    public function getAppTranslationKey(
        GetAppTranslationKeyRequestDto $requestDto,
        AppTranslationsMaintenanceService $appTranslationsMaintenanceService,
        AppTranslationKeysService $appTranslationKeysService
    ): AppTextsCardResponseDto {
        if ($requestDto->appTranslationKeyId === null && trim((string)$requestDto->key) === '') {
            throw new BadRequestException('Pass appTranslationKeyId, or key with the exact key string (' . static::FIND_TOOL . ' finds keys by wording).');
        }
        $appTranslationKey = $this->findKey($requestDto->appTranslationKeyId, $requestDto->key, $appTranslationKeysService);
        if ($appTranslationKey === null) {
            throw AdminToolRecordNotFoundException::forRecord(
                static::ENTITY_LABEL,
                $requestDto->appTranslationKeyId ?? LlmTextRendering::displayValue(trim((string)$requestDto->key)),
                static::FIND_TOOL
            );
        }
        $key = (string)$appTranslationKey->key;
        $appTranslationArea = $appTranslationsMaintenanceService->getAreaOfKey($key);
        $languageCodesById = $appTranslationsMaintenanceService->findLanguageCodesById();
        $activeLanguageCodesById = $appTranslationsMaintenanceService->findActiveLanguageCodesById();
        $onlyLanguageCode = mb_strtolower(trim((string)$requestDto->languageCode));
        if ($onlyLanguageCode !== '' && !in_array($onlyLanguageCode, $languageCodesById, true)) {
            throw new BadRequestException(
                'No language with the code ' . LlmTextRendering::displayValue($onlyLanguageCode) . '. Active languages: '
                . implode(', ', $activeLanguageCodesById) . '.'
            );
        }
        $hasLongText = false;
        $wholeTextCharacters = 0;

        $responseDto = new AppTextsCardResponseDto();
        $responseDto->id = (int)$appTranslationKey->id;
        $responseDto->untrustedSource = static::UNTRUSTED_SOURCE;
        $responseDto->setField('key', $key);
        $responseDto->setField('area', $appTranslationArea->name);
        $responseDto->storedTextLines[] = '#' . $appTranslationKey->id . ' ' . LlmTextRendering::displayValue($key) . ' · ' . $appTranslationArea->name;
        if (trim((string)($appTranslationKey->translationTemplate ?? '')) !== '') {
            $responseDto->storedTextLines[] = 'Reference text for translators: ' . static::describeText((string)$appTranslationKey->translationTemplate);
        }
        if (trim((string)($appTranslationKey->translationHint ?? '')) !== '') {
            $responseDto->storedTextLines[] = 'Translator hint: ' . static::describeText((string)$appTranslationKey->translationHint);
        }
        $languageIdsWithText = [];
        $textLines = [];
        foreach ($appTranslationsMaintenanceService->findStoredTextsOfKeys([(int)$appTranslationKey->id])->getElements() as $appTranslationStoredText) {
            $languageId = $appTranslationStoredText->languageId;
            $languageCode = $languageCodesById[$languageId] ?? ('language #' . $languageId);
            $languageIdsWithText[$languageId] = true;
            if ($onlyLanguageCode !== '' && $languageCode !== $onlyLanguageCode) {
                continue;
            }
            $storedText = $appTranslationStoredText->translation;
            if ($appTranslationStoredText->isCountryNeutral()) {
                $responseDto->setField($languageCode . '_' . $appTranslationStoredText->writingStyle, $storedText);
            }
            $hasLongText = $hasLongText || mb_strlen(LlmTextRendering::toSingleLine($storedText)) > static::CARD_TEXT_LENGTH;
            $textLines[$languageCode . ' ' . $appTranslationStoredText->writingStyle . ' ' . $appTranslationStoredText->appTranslationValueId] = $languageCode . ' ' . $appTranslationStoredText->writingStyle
                . ($appTranslationStoredText->isCountryNeutral() ? '' : ' (country #' . $appTranslationStoredText->countryId . ' only)')
                . ($appTranslationStoredText->context !== Text::CONTEXT_ONE ? ' (' . $appTranslationStoredText->context . ')' : '')
                . ': ' . ($onlyLanguageCode !== '' && $wholeTextCharacters + mb_strlen($storedText) <= static::MAX_WHOLE_TEXT_CHARACTERS
                    ? '"' . LlmTextRendering::toSingleLine($storedText) . '"'
                    : static::describeText($storedText));
            if ($onlyLanguageCode !== '') {
                $wholeTextCharacters += mb_strlen($storedText);
            }
        }
        if ($onlyLanguageCode !== '' && $textLines === []) {
            $responseDto->lines[] = 'No text in ' . $onlyLanguageCode . ' is stored for this key.';
        } elseif ($onlyLanguageCode === '' && $hasLongText) {
            $responseDto->lines[] = 'A text shown by its start is read whole with languageCode.';
        }
        ksort($textLines, SORT_STRING);
        array_push($responseDto->storedTextLines, ...array_slice(array_values($textLines), 0, static::MAX_CARD_TEXT_LINES));
        if (count($textLines) > static::MAX_CARD_TEXT_LINES) {
            $responseDto->lines[] = (count($textLines) - static::MAX_CARD_TEXT_LINES) . ' more stored texts are not shown.';
        }

        $missingLanguageCodes = array_values(array_diff_key($activeLanguageCodesById, $languageIdsWithText));
        $responseDto->setField('missingLanguageCodes', implode(',', $missingLanguageCodes));
        $flags = [];
        if ($appTranslationKey->requiresContext ?? false) {
            $flags[] = 'has singular and plural forms';
        }
        if ($appTranslationKey->doNotTranslateAutomatically ?? false) {
            $flags[] = 'left out of machine translation';
        }
        if ($appTranslationKey->reTranslate ?? false) {
            $flags[] = 'marked to be translated again';
        }
        $responseDto->lines[] = count($languageIdsWithText) . ' of ' . count($activeLanguageCodesById) . ' active languages have a text'
            . ($missingLanguageCodes !== [] ? '; missing: ' . implode(', ', $missingLanguageCodes) . '.' : '; none is missing.')
            . ($flags !== [] ? ' Key: ' . implode(', ', $flags) . '.' : '');
        if ($appTranslationArea->isSyncedFromCode) {
            $responseDto->lines[] = 'The key and its English text come from the code: English is changed there, the other languages here.';
        }
        return $responseDto;
    }

    /**
     * How complete the UI texts are: per area the number of keys and, per active language and address form, how
     * many keys have a text and how many lack one. Pass area for one area only.
     *
     * @example {}
     * @example {"area": "SERVER"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('Completeness of the UI texts per area and language: keys with a text and keys without.')]
    public function getAppTranslationCompleteness(
        GetAppTranslationCompletenessRequestDto $requestDto,
        AppTranslationsMaintenanceService $appTranslationsMaintenanceService
    ): AppTextsCardResponseDto {
        $appTranslationAreas = $appTranslationsMaintenanceService->getAreas();
        $onlyArea = $this->findAreaForRead($requestDto->area, $appTranslationAreas);
        $responseDto = new AppTextsCardResponseDto();
        $completeSummariesByArea = [];
        $incompleteSummariesByArea = [];
        $keyCountsByArea = [];
        foreach ($appTranslationsMaintenanceService->getCompleteness()->getElements() as $appTranslationAreaCompleteness) {
            $areaName = $appTranslationAreaCompleteness->areaName;
            if ($onlyArea !== null && $onlyArea->name !== $areaName) {
                continue;
            }
            $keyCountsByArea[$areaName] = $appTranslationAreaCompleteness->keyCount;
            $responseDto->setField(
                $areaName . '_' . $appTranslationAreaCompleteness->languageCode . '_' . $appTranslationAreaCompleteness->writingStyle . '_missing',
                $appTranslationAreaCompleteness->getMissingKeyCount()
            );
            if ($appTranslationAreaCompleteness->getMissingKeyCount() === 0) {
                $completeSummariesByArea[$areaName][] = $appTranslationAreaCompleteness->languageCode . ' ' . $appTranslationAreaCompleteness->writingStyle;
            } else {
                $incompleteSummariesByArea[$areaName][] = $appTranslationAreaCompleteness->getSummary();
            }
        }
        foreach ($keyCountsByArea as $areaName => $keyCount) {
            $responseDto->setField($areaName . '_keyCount', $keyCount);
            $responseDto->lines[] = '## ' . ($appTranslationAreas->findByName($areaName)?->getDescription() ?? $areaName) . ': ' . number_format($keyCount) . ' keys';
            $responseDto->lines[] = 'Complete: ' . (($completeSummariesByArea[$areaName] ?? []) !== [] ? implode(', ', $completeSummariesByArea[$areaName]) : 'no language');
            $responseDto->lines[] = 'Incomplete: ' . (($incompleteSummariesByArea[$areaName] ?? []) !== [] ? implode(' · ', $incompleteSummariesByArea[$areaName]) : 'no language');
        }
        $responseDto->lines[] = 'Counted per active language and the address forms the area keeps for it (INFORMAL, FORMAL). The keys behind a gap: '
            . static::FIND_TOOL . ' with area and missingLanguageCode.';
        return $responseDto;
    }

    /**
     * The locales (language and country) the texts are offered in, with the address forms the language is written in
     * and whether its language is active. Pass languageCode for the locales of one language.
     *
     * @example {}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('The active locales (language and country) with the address forms of each language.')]
    public function listActiveLocales(ListActiveLocalesRequestDto $requestDto, AppTranslationsMaintenanceService $appTranslationsMaintenanceService): AppTextsCardResponseDto
    {
        $languageCodeFilter = mb_strtolower(trim((string)$requestDto->languageCode));
        $localeLines = [];
        foreach ($appTranslationsMaintenanceService->findActiveLocales()->getElements() as $locale) {
            $languageCode = (string)($locale->languageCode ?? '');
            if ($languageCodeFilter !== '' && $languageCode !== $languageCodeFilter) {
                continue;
            }
            $language = $locale->language ?? null;
            $localeCode = $languageCode . (($locale->countryShortCode ?? null) ? '-' . $locale->countryShortCode : '');
            $localeLines[$localeCode] = $localeCode
                . (trim((string)($locale->name ?? '')) !== '' ? ' · ' . LlmTextRendering::toSingleLine((string)$locale->name) : '')
                . (($locale->isDefaultLocaleForLanguage ?? false) ? ' · default locale of ' . $languageCode : '')
                . ($language instanceof Language
                    ? ' · written ' . str_replace('_', ' ', (string)$language->supportedWritingStyles)
                        . ($language->isActive ? '' : ' · language NOT active: no machine translation, not offered')
                    : '');
        }
        ksort($localeLines, SORT_STRING);
        $responseDto = new AppTextsCardResponseDto();
        $responseDto->setField('localeCount', count($localeLines));
        if ($localeLines === []) {
            $responseDto->lines[] = $languageCodeFilter !== '' ? 'No active locale of language ' . $languageCodeFilter . '.' : 'No locale is active.';
            return $responseDto;
        }
        $responseDto->lines[] = count($localeLines) . ' active locales, ordered by code:';
        array_push($responseDto->lines, ...array_values($localeLines));
        return $responseDto;
    }

    /**
     * The glossary of one language: the fixed product terms and, per term, the wordings it replaces. Machine
     * translation and the text checks follow it. Reads only: the glossary is kept in the code.
     *
     * @example {"languageCode": "de"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('The glossary of a language: fixed product terms and the wordings each replaces.')]
    public function listTranslationDefaultTerms(
        ListTranslationDefaultTermsRequestDto $requestDto,
        AppTranslationDefaultTermsService $appTranslationDefaultTermsService,
        AppTranslationsMaintenanceService $appTranslationsMaintenanceService,
        LanguagesService $languagesService
    ): AppTextsCardResponseDto {
        if (trim((string)$requestDto->languageCode) === '') {
            throw new BadRequestException('Pass languageCode, e.g. de.');
        }
        $language = $this->findLanguageForRead($requestDto->languageCode, $languagesService, $appTranslationsMaintenanceService);
        $variantsByDefaultTerm = [];
        foreach ($appTranslationDefaultTermsService->findDefaultTermsForLanguageId((int)$language->id)?->getElements() ?? [] as $appTranslationDefaultTerm) {
            if ($appTranslationDefaultTerm->isDefaultTerm) {
                $variantsByDefaultTerm[$appTranslationDefaultTerm->term] ??= [];
                continue;
            }
            $defaultTerm = $appTranslationDefaultTerm->defaultTerm ?? null;
            if ($defaultTerm !== null) {
                $variantsByDefaultTerm[$defaultTerm->term][] = trim($appTranslationDefaultTerm->term);
            }
        }
        $searchText = mb_strtolower(trim((string)$requestDto->searchText));
        $termLines = [];
        foreach ($variantsByDefaultTerm as $defaultTerm => $variants) {
            $termLine = $defaultTerm . ($variants !== [] ? ' · instead of: ' . implode(', ', $variants) : '');
            if ($searchText === '' || str_contains(mb_strtolower($termLine), $searchText)) {
                $termLines[] = $termLine;
            }
        }
        sort($termLines, SORT_STRING | SORT_FLAG_CASE);
        $responseDto = new AppTextsCardResponseDto();
        $responseDto->setField('termCount', count($termLines));
        if ($termLines === []) {
            $responseDto->lines[] = 'The glossary of ' . $language->languageCode . ' has no term' . ($searchText !== '' ? ' that contains "' . $searchText . '"' : '') . '.';
        } else {
            $shownTermLines = array_slice($termLines, 0, static::MAX_DEFAULT_TERMS);
            $responseDto->lines[] = count($termLines) . ' glossary terms of ' . $language->languageCode
                . (count($termLines) > count($shownTermLines) ? ', the first ' . count($shownTermLines) . ' (narrow with searchText)' : '') . ':';
            array_push($responseDto->lines, ...$shownTermLines);
        }
        $responseDto->lines[] = 'The glossary is kept in the code and loaded from there: a term is added or changed by the developers, not here.';
        return $responseDto;
    }

    /**
     * Creates a server text key: the English sentence the server shows (a mail line, a push text, an error message),
     * exactly as the code passes it. Keys of an area synced from the code (an app, a web) are not created here.
     * Texts of the other languages are set afterwards with setAppTranslationValue.
     *
     * @example {"key": "Your prize is on its way", "translationHint": "Push text after a prize was shipped"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolSummary('Create a server text key (the English sentence) with an optional translator hint.')]
    public function createAppTranslationKey(
        CreateAppTranslationKeyRequestDto $requestDto,
        AppTranslationsMaintenanceService $appTranslationsMaintenanceService,
        AppTranslationKeysService $appTranslationKeysService
    ): AdminSavedFieldsResponseDto {
        $key = trim((string)$requestDto->key);
        if ($key === '') {
            throw AdminToolNotSavedException::missingArgument('key', 'the English sentence as the server shows it');
        }
        if (mb_strlen($key) > static::MAX_TEXT_LENGTH) {
            throw AdminToolNotSavedException::because('the key has ' . mb_strlen($key) . ' characters, at most ' . static::MAX_TEXT_LENGTH . ' are stored', 'Pass a shorter sentence');
        }
        $appTranslationArea = $appTranslationsMaintenanceService->getAreaOfKey($key);
        if ($appTranslationArea->isSyncedFromCode) {
            throw AdminToolNotSavedException::because(
                'keys of the area ' . $appTranslationArea->getDescription() . ' are created from the code',
                'Tell the operator that a new text there is added by the developers; a server text key is the plain English sentence'
            );
        }
        $responseDto = new AdminSavedFieldsResponseDto();
        $responseDto->entityLabel = static::ENTITY_LABEL;
        $existingKey = $appTranslationKeysService->findByKeyString($key);
        if ($existingKey !== null) {
            $responseDto->entityId = (int)$existingKey->id;
            $responseDto->entityName = LlmTextRendering::shorten($key, 80);
            $responseDto->noChangeLines[] = 'No change: text key #' . $existingKey->id . ' ' . LlmTextRendering::displayValue($key) . ' exists already. Read it with getAppTranslationKey.';
            $responseDto->applied = false;
            return $responseDto;
        }
        $appTranslationKey = $appTranslationsMaintenanceService->createKey($key, $requestDto->translationHint);
        $responseDto->isCreated = true;
        $responseDto->applied = true;
        $responseDto->entityId = (int)$appTranslationKey->id;
        $responseDto->entityName = LlmTextRendering::shorten($key, 80);
        $responseDto->noteLines[] = 'Area ' . $appTranslationArea->name . '. English text: the key itself. Translator hint: '
            . LlmTextRendering::displayValue($appTranslationKey->translationHint ?? null) . '.';
        $responseDto->noteLines[] = 'No other language has a text yet: set one with setAppTranslationValue (appTranslationKeyId ' . $appTranslationKey->id . ').';
        return $responseDto;
    }

    /**
     * Sets the text of one UI text key in one language: one call, the stored text comes back in the result. Name the
     * key by appTranslationKeyId or by its exact key string. The text has to keep the placeholders of the stored text
     * (%name% or {name}). Leave writingStyle out unless the result asks for it; BOTH stores the text for the formal
     * and the informal address. The English text of a key synced from the code is not set here.
     *
     * @example {"appTranslationKeyId": 4711, "languageCode": "de", "text": "Fahrt starten"}
     * @example {"key": "Your prize is on its way", "languageCode": "de", "text": "Dein Preis ist unterwegs", "writingStyle": "BOTH"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolSummary('Set the text of one UI text key in one language (and address form).')]
    public function setAppTranslationValue(
        SetAppTranslationValueRequestDto $requestDto,
        AppTranslationsMaintenanceService $appTranslationsMaintenanceService,
        AppTranslationKeysService $appTranslationKeysService,
        LanguagesService $languagesService
    ): AdminSavedFieldsResponseDto {
        if ($requestDto->appTranslationKeyId === null && trim((string)$requestDto->key) === '') {
            throw AdminToolNotSavedException::missingArgument('appTranslationKeyId', 'the id of the key (' . static::FIND_TOOL . '), or key with the exact key string');
        }
        $languageCode = mb_strtolower(trim((string)$requestDto->languageCode));
        if ($languageCode === '') {
            throw AdminToolNotSavedException::missingArgument('languageCode', 'the language of the text, e.g. de');
        }
        if ($requestDto->text === null) {
            throw AdminToolNotSavedException::missingArgument('text', 'the whole new text');
        }
        $text = trim($requestDto->text);
        if ($text === '') {
            throw AdminToolNotSavedException::because('text was passed empty', 'Pass the new text; a text is never cleared here');
        }
        if (mb_strlen($text) > static::MAX_TEXT_LENGTH) {
            throw AdminToolNotSavedException::because('the text has ' . mb_strlen($text) . ' characters, at most ' . static::MAX_TEXT_LENGTH . ' are stored', 'Pass a shorter text');
        }
        $appTranslationKey = $this->findKey($requestDto->appTranslationKeyId, $requestDto->key, $appTranslationKeysService);
        if ($appTranslationKey === null) {
            throw AdminToolNotSavedException::unknownRecord(
                static::ENTITY_LABEL,
                $requestDto->appTranslationKeyId ?? LlmTextRendering::displayValue(trim((string)$requestDto->key)),
                static::FIND_TOOL
            );
        }
        $key = (string)$appTranslationKey->key;
        $appTranslationArea = $appTranslationsMaintenanceService->getAreaOfKey($key);
        $language = $languagesService->findByLanguageCode($languageCode);
        if ($language === null) {
            throw AdminToolNotSavedException::because(
                'no language with the code ' . LlmTextRendering::displayValue($languageCode),
                'Pass one of ' . implode(', ', $appTranslationsMaintenanceService->findActiveLanguageCodesById())
            );
        }
        if ($languageCode === AppTranslationsMaintenanceService::REFERENCE_LANGUAGE_CODE && $appTranslationArea->isSyncedFromCode) {
            throw AdminToolNotSavedException::because(
                'the English text of a key of ' . $appTranslationArea->name . ' comes from the code, and the next sync from the code writes it back',
                'Tell the operator that the English wording is changed by the developers; the other languages can be set here'
            );
        }

        $storedTextsByStyle = [];
        foreach ([Text::WRITING_STYLE_FORMAL, Text::WRITING_STYLE_INFORMAL] as $writingStyle) {
            $storedText = $appTranslationsMaintenanceService->findStoredText($appTranslationKey, $language, $writingStyle);
            if ($storedText !== null) {
                $storedTextsByStyle[$writingStyle] = $storedText;
            }
        }
        $writingStyles = $this->resolveWritingStyles($requestDto->writingStyle, $appTranslationArea, $language, $storedTextsByStyle);

        $referenceText = $appTranslationsMaintenanceService->findReferenceText($appTranslationKey);
        $placeholderProblem = $referenceText !== null && $languageCode !== AppTranslationsMaintenanceService::REFERENCE_LANGUAGE_CODE
            ? $appTranslationsMaintenanceService->findPlaceholderProblem($appTranslationKey, $referenceText, $text, $languageCode)
            : null;
        if ($placeholderProblem !== null) {
            throw AdminToolNotSavedException::because(
                $placeholderProblem . '; reference text ' . LlmTextRendering::displayValue($referenceText),
                'If the operator\'s wording has the placeholders, pass it again unchanged; if not, tell the operator which placeholders the text has to keep and ask for the wording, never place them yourself'
            );
        }

        $responseDto = new AdminSavedFieldsResponseDto();
        $responseDto->entityLabel = static::ENTITY_LABEL;
        $responseDto->entityId = (int)$appTranslationKey->id;
        $responseDto->entityName = LlmTextRendering::shorten(LlmTextRendering::toSingleLine($key), 80);
        foreach ($writingStyles as $writingStyle) {
            $fieldLabel = $languageCode . ' ' . $writingStyle;
            $storedText = $storedTextsByStyle[$writingStyle] ?? null;
            if ($storedText === $text) {
                $responseDto->addUnchangedField($fieldLabel, LlmTextRendering::displayValue($storedText));
                continue;
            }
            $appTranslationsMaintenanceService->setValue($appTranslationKey, $language, $writingStyle, $text);
            $responseDto->addSavedField(
                $fieldLabel,
                LlmTextRendering::displayValue($storedText),
                LlmTextRendering::displayValue($appTranslationsMaintenanceService->findStoredText($appTranslationKey, $language, $writingStyle))
            );
        }
        $responseDto->applied = $responseDto->savedFieldLines !== [];
        if ($responseDto->applied) {
            foreach (array_diff_key($storedTextsByStyle, array_flip($writingStyles)) as $otherStyle => $otherText) {
                $responseDto->noteLines[] = $languageCode . ' ' . $otherStyle . ' stays ' . LlmTextRendering::displayValue($otherText) . '.';
            }
            if ($appTranslationArea->liveNote !== null && $appTranslationArea->liveNote !== '') {
                $responseDto->noteLines[] = $appTranslationArea->liveNote;
            }
            if (!$language->isActive) {
                $responseDto->noteLines[] = 'Language ' . $languageCode . ' is not active: the text is stored and shown once the language is switched on.';
            }
        }
        return $responseDto;
    }

    /**
     * The address forms a call writes: the named one, or without a name the one the area keeps or the key has.
     *
     * @param string|null $requestedWritingStyle INFORMAL, FORMAL, BOTH or null
     * @param AppTranslationArea $appTranslationArea
     * @param Language $language
     * @param string[] $storedTextsByStyle The stored texts of the key in the language, by writing style
     * @return string[] Text::WRITING_STYLE_*
     * @throws AdminToolNotSavedException When the style is not kept by the area or cannot be decided
     */
    protected function resolveWritingStyles(?string $requestedWritingStyle, AppTranslationArea $appTranslationArea, Language $language, array $storedTextsByStyle): array
    {
        $languageCode = (string)$language->languageCode;
        if ($appTranslationArea->writingStyles !== []) {
            $requestedWritingStyles = match ($requestedWritingStyle) {
                null => $appTranslationArea->writingStyles,
                SetAppTranslationValueRequestDto::WRITING_STYLE_BOTH => [Text::WRITING_STYLE_FORMAL, Text::WRITING_STYLE_INFORMAL],
                default => [$requestedWritingStyle],
            };
            $notKeptWritingStyles = array_diff($requestedWritingStyles, $appTranslationArea->writingStyles);
            if ($notKeptWritingStyles !== [] && $requestedWritingStyle !== SetAppTranslationValueRequestDto::WRITING_STYLE_BOTH) {
                throw AdminToolNotSavedException::because(
                    'the area ' . $appTranslationArea->name . ' shows the ' . implode(' and ', $appTranslationArea->writingStyles) . ' text only',
                    'Call again without writingStyle'
                );
            }
            if (count($appTranslationArea->writingStyles) === 1 || $requestedWritingStyle !== null) {
                return array_values(array_intersect($requestedWritingStyles, $appTranslationArea->writingStyles));
            }
        }
        if ($requestedWritingStyle === SetAppTranslationValueRequestDto::WRITING_STYLE_BOTH) {
            return [Text::WRITING_STYLE_FORMAL, Text::WRITING_STYLE_INFORMAL];
        }
        if ($requestedWritingStyle !== null) {
            return [$requestedWritingStyle];
        }
        if (count($storedTextsByStyle) === 1) {
            return [(string)array_key_first($storedTextsByStyle)];
        }
        if (count($storedTextsByStyle) > 1) {
            if (count(array_unique($storedTextsByStyle)) === 1) {
                return array_keys($storedTextsByStyle);
            }
            $storedParts = [];
            foreach ($storedTextsByStyle as $writingStyle => $storedText) {
                $storedParts[] = $writingStyle . ' ' . LlmTextRendering::displayValue($storedText);
            }
            throw AdminToolNotSavedException::because(
                $languageCode . ' has two different texts for this key: ' . implode(', ', $storedParts),
                static::WRITING_STYLE_NEXT_MOVE
            );
        }
        $writingStylesOfLanguage = $appTranslationArea->getWritingStylesOfLanguage($language);
        if (count($writingStylesOfLanguage) === 1) {
            return $writingStylesOfLanguage;
        }
        throw AdminToolNotSavedException::because(
            'the key has no ' . $languageCode . ' text yet, and ' . $languageCode . ' is written formally and informally',
            static::WRITING_STYLE_NEXT_MOVE
        );
    }

    /**
     * Example: `#4711 "mobile.ride.start" · APP · has de, en, fr · missing it, tr · en: "Start ride"`
     *
     * @param AppTranslationKeyCoverage $appTranslationKeyCoverage
     * @return string
     */
    protected function buildKeyCoverageLine(AppTranslationKeyCoverage $appTranslationKeyCoverage): string
    {
        $parts = [
            '#' . $appTranslationKeyCoverage->appTranslationKeyId . ' "'
            . LlmTextRendering::shorten(LlmTextRendering::toSingleLine($appTranslationKeyCoverage->key), static::LIST_LINE_KEY_LENGTH) . '"',
            $appTranslationKeyCoverage->areaName,
            $appTranslationKeyCoverage->languageCodesWithText !== [] ? 'has ' . implode(', ', $appTranslationKeyCoverage->languageCodesWithText) : 'no text in any language',
            $appTranslationKeyCoverage->missingLanguageCodes !== [] ? 'missing ' . implode(', ', $appTranslationKeyCoverage->missingLanguageCodes) : 'missing none',
        ];
        if ($appTranslationKeyCoverage->sampleText !== null && $appTranslationKeyCoverage->sampleText !== '') {
            $parts[] = ($appTranslationKeyCoverage->sampleLanguageCode ?? '?') . ': "'
                . LlmTextRendering::shorten(LlmTextRendering::toSingleLine($appTranslationKeyCoverage->sampleText), static::LIST_LINE_TEXT_LENGTH) . '"';
        }
        return implode(' · ', $parts);
    }

    /**
     * @param string|null $areaName
     * @param AppTranslationAreas $appTranslationAreas
     * @return AppTranslationArea|null null when no area was passed
     * @throws BadRequestException When the area is unknown
     */
    protected function findAreaForRead(?string $areaName, AppTranslationAreas $appTranslationAreas): ?AppTranslationArea
    {
        if (trim((string)$areaName) === '') {
            return null;
        }
        $appTranslationArea = $appTranslationAreas->findByName((string)$areaName);
        if ($appTranslationArea === null) {
            throw new BadRequestException(
                'No area ' . LlmTextRendering::displayValue(trim((string)$areaName)) . '. Areas: ' . implode(', ', $appTranslationAreas->getNames()) . '.'
            );
        }
        return $appTranslationArea;
    }

    /**
     * @param int|null $appTranslationKeyId
     * @param string|null $key The exact key string
     * @param AppTranslationKeysService $appTranslationKeysService
     * @return AppTranslationKey|null
     */
    protected function findKey(?int $appTranslationKeyId, ?string $key, AppTranslationKeysService $appTranslationKeysService): ?AppTranslationKey
    {
        if ($appTranslationKeyId !== null) {
            return $appTranslationKeyId > 0 ? $appTranslationKeysService->find($appTranslationKeyId, false) : null;
        }
        return trim((string)$key) !== '' ? $appTranslationKeysService->findByKeyString(trim((string)$key)) : null;
    }

    /**
     * @param string $languageCode
     * @param LanguagesService $languagesService
     * @param AppTranslationsMaintenanceService $appTranslationsMaintenanceService
     * @return Language
     * @throws BadRequestException When no language has the code
     */
    protected function findLanguageForRead(string $languageCode, LanguagesService $languagesService, AppTranslationsMaintenanceService $appTranslationsMaintenanceService): Language
    {
        $normalizedLanguageCode = mb_strtolower(trim($languageCode));
        $language = $languagesService->findByLanguageCode($normalizedLanguageCode);
        if ($language === null) {
            throw new BadRequestException(
                'No language with the code ' . LlmTextRendering::displayValue($normalizedLanguageCode) . '. Active languages: '
                . implode(', ', $appTranslationsMaintenanceService->findActiveLanguageCodesById()) . '.'
            );
        }
        return $language;
    }

    /**
     * A stored text as a card shows it: whole up to CARD_TEXT_LENGTH characters, longer ones by length and start.
     * Examples: `"Fahrt starten"` · `1,204 characters, starts "Liebe Teilnehmer, die Sommeraktion …"`
     *
     * @param string $text
     * @return string
     */
    protected static function describeText(string $text): string
    {
        $singleLineText = LlmTextRendering::toSingleLine($text);
        if (mb_strlen($singleLineText) <= static::CARD_TEXT_LENGTH) {
            return '"' . $singleLineText . '"';
        }
        return number_format(mb_strlen($text)) . ' characters, starts "' . LlmTextRendering::shorten($singleLineText, LlmTextRendering::DESCRIPTOR_PREVIEW_LENGTH) . '"';
    }
}
