<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Translations\Dtos;

use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Mcp\Admin\Common\Dtos\AdminListRequestDto;

/** Which UI text keys: every criterion optional, all given ones have to hold. */
class FindAppTranslationKeysRequestDto extends AdminListRequestDto
{
    /** @var string|null Words as they appear in the key or in one of its texts, e.g. the wording seen on a screen */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $searchText = null;

    /** @var string|null With searchText: search the texts of this language only, e.g. de */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $languageCode = null;

    /** @var string|null Where the text is shown: the name of an area as getAppTranslationCompleteness lists them, e.g. SERVER */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $area = null;

    /** @var string|null Only keys that have no text in this language, e.g. fr */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $missingLanguageCode = null;
}
