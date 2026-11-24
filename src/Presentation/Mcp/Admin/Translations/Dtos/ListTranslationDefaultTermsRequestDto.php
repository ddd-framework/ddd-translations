<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Translations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The glossary of which language. */
class ListTranslationDefaultTermsRequestDto extends RequestDto
{
    /** @var string|null (required) The language of the glossary, e.g. de */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $languageCode = null;

    /** @var string|null Only terms that contain these letters (the fixed term or a wording it replaces) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $searchText = null;
}
