<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Translations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Which UI text key: by id or by its exact key string. */
class GetAppTranslationKeyRequestDto extends RequestDto
{
    /** @var int|null The id of the key (findAppTranslationKeys) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $appTranslationKeyId = null;

    /** @var string|null The exact key string, when no id is known */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $key = null;

    /** @var string|null Only the texts of this language, each shown whole (a long text is otherwise shown by its start), e.g. de */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $languageCode = null;
}
