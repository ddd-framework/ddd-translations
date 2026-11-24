<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Translations\Dtos;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Mcp\Admin\Common\AdminToolResultLines;

/**
 * The card a UI text tool answers with: the lines that carry stored texts (one untrusted passage), the other lines,
 * and the flat values a state check of an evaluation reads (`fields.<name>`). The DTO composes; it formats no text.
 *
 * Example (one key):
 *   #4711 "mobile.ride.start" · APP
 *   de INFORMAL: "Fahrt starten"
 *   2 of 14 active languages have a text; missing: bg, cs, …
 */
class AppTextsCardResponseDto extends RestResponseDto implements LlmMarkdownRenderable, MCPToolResponsePlatformNoteProvider
{
    /** @var int The id of the key, 0 when the card shows no single key */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $id = 0;

    /** @var string[] The stored values a state check reads, by name (each as text; an empty text is "not set") */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $fields = [];

    /** @var string[] The lines that carry stored texts (one untrusted passage) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public array $storedTextLines = [];

    /** @var string[] The other lines of the card, in order */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $lines = [];

    /** @var string|null What the untrusted passage is */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $untrustedSource = null;

    /**
     * @param string $fieldName
     * @param string|int|bool|null $value
     * @return void
     */
    public function setField(string $fieldName, string|int|bool|null $value): void
    {
        $this->fields[$fieldName] = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string)$value,
        };
    }

    /**
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        $resultLines = [];
        if ($this->storedTextLines !== []) {
            $resultLines[] = AdminToolResultLines::wrapUntrustedLines($this->untrustedSource ?? 'stored texts', $this->storedTextLines);
        }
        array_push($resultLines, ...$this->lines);
        return implode("\n", $resultLines);
    }

    /**
     * @return string|null
     */
    public function buildPlatformNoteForModel(): ?string
    {
        return $this->storedTextLines !== [] ? ToolResultUntrustedSegments::READING_RULE : null;
    }
}
