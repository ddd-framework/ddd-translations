<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Translations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Which area; without one every area. */
class GetAppTranslationCompletenessRequestDto extends RequestDto
{
    /** @var string|null Only this area, by name; without it the result lists every area */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $area = null;
}
