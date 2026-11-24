<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Translations\Dtos;

use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The new text of one UI text key in one language. Name the key by id or by its exact key string. */
class SetAppTranslationValueRequestDto extends RequestDto
{
    /** @var string Stores the same text for the formal and the informal address */
    public const string WRITING_STYLE_BOTH = 'BOTH';

    /** @var string[] */
    public const array WRITING_STYLES = ['INFORMAL', 'FORMAL', self::WRITING_STYLE_BOTH];

    /** @var int|null The id of the key (findAppTranslationKeys) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $appTranslationKeyId = null;

    /** @var string|null The exact key string, when no id is known */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $key = null;

    /** @var string|null (required) The language of the text, e.g. de */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $languageCode = null;

    /** @var string|null (required) The whole new text, placeholders exactly as in the stored text */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $text = null;

    /** @var string|null INFORMAL (du), FORMAL (Sie) or BOTH. Leave out when the language has one text for the key; the result asks when it matters */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Choice(choices: self::WRITING_STYLES)]
    public ?string $writingStyle = null;
}
