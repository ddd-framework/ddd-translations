<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Translations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Which locales. */
class ListActiveLocalesRequestDto extends RequestDto
{
    /** @var string|null Only the locales of this language, e.g. de */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $languageCode = null;
}
