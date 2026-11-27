# mgamadeus/ddd-common-translations -- Translations Module

Texts, entity translations, app UI translations, embeddings, and language detection for the `mgamadeus/ddd` framework.

**Package:** `mgamadeus/ddd-common-translations` (v1.2.x)
**Namespace:** `DDD\`
**Depends on:** `mgamadeus/ddd` (^3.40), `mgamadeus/ddd-ai` (^3.3), `mgamadeus/ddd-common-money` (^1.0), `mgamadeus/ddd-common-political` (^1.0)

> **This module follows all DDD Core conventions.** For base patterns, see `vendor/mgamadeus/ddd/AGENTS.md` and skills in `vendor/mgamadeus/ddd`. For AI patterns, see `vendor/mgamadeus/ddd-ai`. For Argus patterns, see `vendor/mgamadeus/ddd-argus`.

## Untrusted Input (mandatory)

No byte or string from outside (uploads, URLs and their responses, third-party API bodies, model output, a client's
claims about a file) is ever executed or interpreted as code. Use the helpers of `DDD\Infrastructure\Untrusted` in
`mgamadeus/ddd` (`RasterImageDecoder`, `GuardedHttpFetchService`, `SafeXml`, `ContainedPath`, `IsolatedProcess`,
`ArchiveInspector`, `UntrustedContentResponseDto`). Any other raw sink call needs an entry with its reason in
`config/untrusted-sinks-allowlist.php`; `tests/UntrustedSinkScanTest.php` enforces it. Rule, helpers and traps: Core
skill `ddd-untrusted-input-specialist`.

## Architecture

```
src/Domain/Common/
+-- Entities/
|   +-- Texts/                         [Text, Texts, Translation, Translations, DetectedLanguage]
|   |   +-- Embeddings/TextEmbedding.php
|   |   +-- Translations/             [Translation, Translations]
|   |   +-- Buckets/TextsBuckets.php  [Batching container]
|   |   +-- TranslationPrompts.php    [Prompt name constants]
|   +-- AppTranslations/               [DB-backed app UI translations]
|       +-- AppTranslationKey.php      [Keys with templates, hints]
|       +-- AppTranslationValue.php    [Per-language/country/style values]
|       +-- AppTranslationDefaultTerm.php [Glossary terms]
|       +-- Completeness/             [Per-locale coverage metrics]
|       +-- Maintenance/              [Areas by key prefix, key coverages, area completeness, stored texts]
+-- Repo/
|   +-- Argus/Texts/                   [ArgusTranslation, ArgusTranslations, ArgusTextEmbedding, ArgusDetectedLanguage]
|   +-- DB/AppTranslations/            [Doctrine repos for AppTranslation* entities]
+-- Services/
|   +-- AppTranslations/               [AppTranslationsService, AppTranslatableService, AppTranslationKeysService, AppTranslationValuesService, AppTranslationsMaintenanceService]
|   +-- Translations/AITranslationsService.php [Entity property translation]
|   +-- Texts/Embeddings/AITextEmbeddingsService.php
+-- MessageHandlers/                   [AppTranslationsHandler + AppTranslationsMessage]
src/Presentation/Mcp/Admin/Translations/ [beside ddd-agents >= 0.3 only: TranslationsAppTextsMCPPackage + Dtos]
config/app/AI/Prompts/Common/         [4 prompt templates]
config/app/AI/Skills/Translations/    [agent skill Translations.AppTexts]
config/app/Common/Translations/       [AppTexts.php: the areas of the UI text keys]
```

## Agent part (optional, beside `mgamadeus/ddd-agents`)

`Translations.AppTexts` ↔ package id `translations.appTexts` (`TranslationsAppTextsMCPPackage`), bound by class in the
host's `skillPackages.php`. Gated by the right `manageTexts` (`AgentAccountRights::MANAGE_TEXTS`) on package and skill,
plus `MCPRequiresAdminWithAccessToAllResources`. `TranslationsModule::getNamespacesRequiringClasses()` keeps the
`Presentation\Mcp\Admin\Translations\` classes unloaded without ddd-agents; ddd-agents is a `suggest` and a dev
dependency only. The areas (`Common.Translations.AppTexts`) and the placeholder check
(`AppTranslationsMaintenanceService::findPlaceholderProblem()`, same-path override) are the host's hooks.

## Two Translation Systems

### 1. Entity Property Translation (AITranslationsService)

Translates `#[Translatable]` properties on entities to multiple locales via AI:

```php
$aiTranslationsService = AITranslationsService::getService();
$aiTranslationsService->translateEntities($entitySet, $locales, storeAutomatically: true);
```

Features: automatic text chunking (max 1000 chars/bucket), Markdown-aware splitting, parallel Argus calls via TextsBuckets.

### 2. App UI Translation (AppTranslationsService)

DB-backed translation key/value system for application UI strings:

- **AppTranslationKey** -- keys with optional templates, hints, context flags (singular/plural)
- **AppTranslationValue** -- per language + country + writing style translations
- **AppTranslationDefaultTerm** -- glossary for consistent terminology
- **Completeness tracking** -- per-locale coverage (formal + informal percentages)

## Key Entities

| Entity | Type | Persisted | Purpose |
|--------|------|-----------|---------|
| **Text** | ValueObject | Argus cache | Base text with locale, writing style, context |
| **Translation** | ValueObject (extends Text) | Argus cache | Translated version of a Text |
| **TextEmbedding** | ValueObject | Argus cache | Vector representation via OpenAI embeddings |
| **DetectedLanguage** | ValueObject | Argus cache | AI-detected language code |
| **AppTranslationKey** | Entity | DB | UI translation key with metadata |
| **AppTranslationValue** | Entity | DB | Per-locale translation value |
| **AppTranslationDefaultTerm** | Entity | DB | Glossary/terminology mapping |

## Async Translation

Via Symfony Messenger (`app_translations` transport):

```php
// Sync
$texts->translate(async: false);

// Async (dispatches AppTranslationsMessage)
$texts->translate(async: true);
```

## AppTranslatableService (DB-Backed `__()`)

Overrides core `TranslatableService::translateKey()` to read from DB instead of config arrays. Fallback chain:
1. Exact: language + country + writingStyle
2. Without country
3. Alternate writing style
4. Default language fallback
5. First available value
6. Return key itself

All steps APC-cached (1h TTL).

## Argus Endpoints

| Repo | Endpoint | Cache | Purpose |
|------|----------|-------|---------|
| `ArgusTranslation` | `POST:/rc-translations/deepl/translate` | 1 day | Single text DeepL |
| `ArgusTranslations` | `POST:/ai/openRouter/chatCompletions` | none | Batch LLM translation |
| `ArgusTextEmbedding` | `POST:/ai/openRouter/embeddings` | 1 day | Text vectorization |
| `ArgusDetectedLanguage` | `POST:/ai/openRouter/chatCompletions` | none | Language detection |

## Contributing: versions and commit messages

- **A pull request never sets a version.** Leave `version` in `composer.json` and in `package.json` as it is, and add
  no version heading to `CHANGELOG.md`. Write the changelog entry under `## Unreleased` at the top of the changelog
  (add the heading when it is missing).
- **The maintainer assigns the version at release:** bumps `composer.json` / `package.json`, renames `## Unreleased` to
  the new version and tags it. Versions are regular only (no pre-release suffixes).
- **Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):** `feat: …`, `fix: …`,
  `docs: …`, `refactor: …`, `test: …`, `chore: …`, optionally with a scope (`fix(chat): …`); a breaking change carries
  `!` after the type or a `BREAKING CHANGE:` footer.
- **Merging a pull request that still carries a version:** keep the version fields of `main`, move its changelog entry
  under `## Unreleased`, then release as usual.
- **Rebase on the current `main` before the merge,** so that only real code conflicts remain.
- **A breaking change is named.** Its changelog entry says what an application must change. The release makes it a
  major version (a package at 1.0 or above) or a minor version (a package still at 0.x).
- **No application terms in a package:** no application's name, and no logic that only one application has (its own
  posts, its own media screens, its own country rules). A package offers hooks; the application individualises
  through them. Review checks this.
- **Tags are never moved or deleted.** A mistake in a release is fixed by a new patch version.
