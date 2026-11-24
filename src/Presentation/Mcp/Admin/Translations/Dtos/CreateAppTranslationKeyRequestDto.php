<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Translations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The new server text key. */
class CreateAppTranslationKeyRequestDto extends RequestDto
{
    /** @var string|null (required) The key: the English sentence exactly as the server passes it, placeholders as %name% */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $key = null;

    /** @var string|null What a translator has to know: where the text is shown, what a placeholder stands for */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $translationHint = null;
}
