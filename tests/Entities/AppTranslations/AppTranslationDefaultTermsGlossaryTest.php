<?php

declare(strict_types=1);

namespace DDD\Tests\Translations\Entities\AppTranslations;

use DDD\Domain\Common\Entities\AppTranslations\AppTranslationDefaultTerm;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationDefaultTerms;
use PHPUnit\Framework\TestCase;

/**
 * The glossary of the app translation prompts: every variant of a language appears as "<variant> => <default term>",
 * with the variants linked to their default terms through defaultTermId.
 */
class AppTranslationDefaultTermsGlossaryTest extends TestCase
{
    protected function createTerm(int $id, string $term, bool $isDefaultTerm, ?int $defaultTermId = null): AppTranslationDefaultTerm
    {
        $appTranslationDefaultTerm = new AppTranslationDefaultTerm();
        $appTranslationDefaultTerm->id = $id;
        $appTranslationDefaultTerm->languageId = 1;
        $appTranslationDefaultTerm->term = $term;
        $appTranslationDefaultTerm->isDefaultTerm = $isDefaultTerm;
        if ($defaultTermId !== null) {
            $appTranslationDefaultTerm->defaultTermId = $defaultTermId;
        }
        return $appTranslationDefaultTerm;
    }

    protected function createTermsSet(AppTranslationDefaultTerm ...$appTranslationDefaultTermsToAdd): AppTranslationDefaultTerms
    {
        $appTranslationDefaultTerms = new AppTranslationDefaultTerms();
        $appTranslationDefaultTerms->add(...$appTranslationDefaultTermsToAdd);
        return $appTranslationDefaultTerms;
    }

    public function testAVariantLoadedOnlyWithItsDefaultTermIdYieldsAGlossaryLine(): void
    {
        $appTranslationDefaultTerms = $this->createTermsSet(
            $this->createTerm(1, 'Fahrrad', true),
            $this->createTerm(2, 'Rad', false, 1),
        );

        $this->assertSame("Rad => Fahrrad\n", $appTranslationDefaultTerms->getDefaultTermsAsAssociationPairs());
        $this->assertSame("Rad;Fahrrad\n", $appTranslationDefaultTerms->getDefaultTermsAsStringPairs());
    }

    public function testMoreThanFiftyTermsAllReachTheGlossary(): void
    {
        $appTranslationDefaultTerms = new AppTranslationDefaultTerms();
        for ($termNumber = 1; $termNumber <= 60; $termNumber++) {
            $defaultTerm = $this->createTerm($termNumber, "Begriff $termNumber", true);
            $variantTerm = $this->createTerm(1000 + $termNumber, "Variante $termNumber", false, $termNumber);
            $appTranslationDefaultTerms->add($defaultTerm, $variantTerm);
        }

        $glossaryLines = array_filter(explode("\n", $appTranslationDefaultTerms->getDefaultTermsAsAssociationPairs()));

        $this->assertCount(60, $glossaryLines);
        $this->assertContains('Variante 1 => Begriff 1', $glossaryLines);
        $this->assertContains('Variante 60 => Begriff 60', $glossaryLines);
    }

    public function testVariantsOfOneDefaultTermShareOneLine(): void
    {
        $appTranslationDefaultTerms = $this->createTermsSet(
            $this->createTerm(1, 'Fahrrad', true),
            $this->createTerm(2, 'Rad', false, 1),
            $this->createTerm(3, 'Velo', false, 1),
        );

        $this->assertSame("Rad, Velo => Fahrrad\n", $appTranslationDefaultTerms->getDefaultTermsAsAssociationPairs());
    }

    public function testAVariantWhoseDefaultTermIsMissingOrItselfIsLeftOut(): void
    {
        $appTranslationDefaultTerms = $this->createTermsSet(
            $this->createTerm(1, 'Fahrrad', true),
            $this->createTerm(2, 'Rad', false, 99),
            $this->createTerm(3, 'Velo', false, 3),
        );

        $this->assertSame('', $appTranslationDefaultTerms->getDefaultTermsAsAssociationPairs());
        $this->assertFalse(isset($appTranslationDefaultTerms->getElements()[2]->defaultTerm));
    }

    public function testAnAlreadyLinkedVariantKeepsItsDefaultTerm(): void
    {
        $otherDefaultTerm = $this->createTerm(50, 'Zweirad', true);
        $variant = $this->createTerm(2, 'Rad', false, 1);
        $variant->defaultTerm = $otherDefaultTerm;
        $appTranslationDefaultTerms = $this->createTermsSet($this->createTerm(1, 'Fahrrad', true), $variant);

        $appTranslationDefaultTerms->linkVariantTermsToDefaultTerms();

        $this->assertSame($otherDefaultTerm, $variant->defaultTerm);
    }
}
