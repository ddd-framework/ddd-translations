# mgamadeus/ddd-common-translations

Texts, entity translations, app UI translations, embeddings, and language detection for the [mgamadeus/ddd](https://github.com/ddd-framework/ddd) framework.

## Installation

```bash
composer require mgamadeus/ddd-common-translations
```

This pulls in [ddd-ai](https://github.com/ddd-framework/ddd-ai) and [ddd-common-political](https://github.com/ddd-framework/ddd-political) automatically.

## What it does

Unified translation system with two main capabilities:

### Entity translations (AITranslationsService)

Translates any entity property marked with `#[Translatable]` to multiple locales via AI:

```php
$aiTranslationsService = AITranslationsService::getService();
$aiTranslationsService->translateEntities($entitySet, $locales);
```

### App UI translations (AppTranslationsService)

Manages application UI string translations with:

- **AppTranslationKey** — translation keys with templates, hints, context flags
- **AppTranslationValue** — per language/country/writing style values
- **AppTranslationDefaultTerm** — glossary for consistent terminology
- **Completeness tracking** — per-locale coverage metrics (formal + informal)
- **AI generation** — batch translate untranslated keys via LLM

```php
$service = AppTranslationsService::getService();

// Generate translations for a locale
$result = $service->generateAppTranslationsForLocale($locale, writingStyle: 'FORMAL');

// Generate for all active locales
$results = $service->generateAppTranslationsForActiveLocales();
```

## Service registration

Add to your project's `services.yaml`:

```yaml
# DDD Module: ddd-common-translations
DDD\Domain\Common\Services\AppTranslations\:
    resource: '%kernel.project_dir%/vendor/mgamadeus/ddd-common-translations/src/Domain/Common/Services/AppTranslations/*'
    public: true

DDD\Domain\Common\Services\Translations\:
    resource: '%kernel.project_dir%/vendor/mgamadeus/ddd-common-translations/src/Domain/Common/Services/Translations/*'
    public: true
```

## AppTranslatableService (DB-backed translations, optional)

By default, the DDD core's `TranslatableService::translateKey()` reads from `config/app/Common/Translations.php` (hardcoded translations array). The module ships `AppTranslatableService` which overrides `translateKey()` to read from `AppTranslationKey` + `AppTranslationValue` entities in the database — letting you manage translations via admin UI instead of code.

To activate, override the core service in your `services.yaml`:

```yaml
# Use DB-backed translations instead of config array
DDD\Domain\Base\Services\TranslatableService:
    class: DDD\Domain\Common\Services\Translations\AppTranslatableService
    public: true
```

The fallback chain is identical to the core (language+country+style → no country → alt writing style → default language → first available → key itself), just sourced from the DB with APC caching at each step (1-hour TTL).

### Text processing

- **Text / Texts** — base text entities with locale, writing style, context
- **Translation** — subclass of Text representing a translated version
- **TextEmbedding** — vector representation via OpenAI embeddings API
- **DetectedLanguage** — AI-powered language detection

## Prompt configuration

The module ships generic prompt templates in `config/app/AI/Prompts/Common/`:

| Prompt | Purpose |
|---|---|
| `Translations/AppTranslations/SingleLocaleFormal.md` | App UI translations (formal tone) |
| `Translations/AppTranslations/SingleLocaleInformal.md` | App UI translations (informal tone) |
| `Translations/Entity/Translatable.md` | Entity property translations |
| `Texts/DetectedLanguage.md` | Language detection |

### Overriding prompts

Place a file at the same path in your project's `config/app/AI/Prompts/` to override. Use this to add domain-specific context, tone, or terminology rules.

### Prompt constants

Use `TranslationPrompts` to reference prompt names:

```php
use DDD\Domain\Common\Entities\Texts\TranslationPrompts;

TranslationPrompts::APP_TRANSLATIONS_SINGLE_LOCALE_FORMAL
TranslationPrompts::APP_TRANSLATIONS_SINGLE_LOCALE_INFORMAL
TranslationPrompts::ENTITY_TRANSLATABLE
TranslationPrompts::DETECTED_LANGUAGE
```

## Async translation processing

The module includes message handlers for asynchronous translation via Symfony Messenger.

### Messenger configuration

Add the `app_translations` transport to your `config/symfony/default/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            app_translations: '%env(MESSENGER_TRANSPORT_DSN_RABBITMQ)%/app_translations'
            # Or for synchronous processing:
            # app_translations: 'sync://'
        routing:
            'DDD\Domain\Common\MessageHandlers\AppTranslationsMessage': app_translations
```

### Message handler service binding

Register the message handler in your `services.yaml` with the messenger logger:

```yaml
DDD\Domain\Common\MessageHandlers\:
    resource: '%kernel.project_dir%/vendor/mgamadeus/ddd-common-translations/src/Domain/Common/MessageHandlers/*Handler.php'
    tags: ['messenger.message_handler']
    bind:
        Psr\Log\LoggerInterface $messengerLogger: '@monolog.logger.messenger'
```

### Environment variables

```env
# RabbitMQ transport DSN for async message processing
MESSENGER_TRANSPORT_DSN_RABBITMQ=amqp://guest:guest@localhost:5672/%2f
```

## Hardcoded fallback translations

The DDD core's `TranslatableService` reads `config/app/Common/Translations.php` as a last-resort fallback for system messages. This file is **project-specific** — each project maintains its own with domain-relevant strings:

```php
// config/app/Common/Translations.php
return [
    'Welcome' => [
        'de::FORMAL' => 'Willkommen',
        'en::FORMAL' => 'Welcome',
        'fr::FORMAL' => 'Bienvenue',
    ],
];
```

The module does not ship this file — create it in your project as needed.

## Agent tools for the UI texts (beside ddd-agents)

With `mgamadeus/ddd-agents` 0.3 or later installed (it is a `suggest`, not a requirement), the module ships the agent
skill `Translations.AppTexts` (`config/app/AI/Skills/Translations/AppTexts.md`) and its tool package
`translations.appTexts` (`DDD\Presentation\Mcp\Admin\Translations\TranslationsAppTextsMCPPackage`): find UI text
keys by wording, area or missing language, read a key in every language, completeness per area and language, active
locales, the glossary, create a server text key, set the text of a key in one language. Without ddd-agents the
package's classes are neither services nor loaded (`TranslationsModule::getNamespacesRequiringClasses()`).

The tools are for platform operators: the package requires the account right `manageTexts`
(`AgentAccountRights::MANAGE_TEXTS`; ddd-agents' default mapping `AI.accountRights` gives it to the superadmins) and an
account with access to every resource. A host binds the skill by class in its `config/app/AI/skillPackages.php`:

```php
use DDD\Presentation\Mcp\Admin\Translations\TranslationsAppTextsMCPPackage;

return [
    // …
    'Translations.AppTexts' => [TranslationsAppTextsMCPPackage::class],
];
```

**Areas.** No column stores where a key is shown; the start of the key string does. The areas are configured in
`config/app/Common/Translations/AppTexts.php` (key `areas`, by name; the module ships one area `SERVER` that holds
every key). A host with an app or a web of its own replaces the file:

```php
return [
    'areas' => [
        'APP' => ['label' => 'the mobile app', 'keyPrefix' => 'mobile.', 'isSyncedFromCode' => true,
                  'writingStyles' => ['INFORMAL'], 'liveNote' => 'The app shows the new text after it loads its texts again.'],
        'WEB' => ['label' => 'the admin web', 'keyPrefix' => 'web.', 'isSyncedFromCode' => true,
                  'liveNote' => 'The web shows the new text after a reload.'],
        'SERVER' => ['label' => 'mails, push and error texts', 'liveNote' => 'Server texts are cached: the new text is used within an hour.'],
    ],
];
```

A key belongs to the area with the longest prefix it starts with; the area without `keyPrefix` holds the rest. Keys of
an area with `isSyncedFromCode` are not created by the tools, and their English text is not set there.
`writingStyles` limits the address forms an area keeps (else those the language is written in).

**Placeholders.** A new text keeps the `%name%` and `{name}` placeholders of its reference text (the translator
template, else the English text of a key synced from the code, else the key string).
`AppTranslationsMaintenanceService::findPlaceholderProblem()` checks it; a host whose texts carry other syntax (ICU
plural blocks, markup) overrides that method in a same-path subclass.

The reads and writes behind the tools are `AppTranslationsMaintenanceService` (container service, usable without
ddd-agents): `findKeyCoverages()`, `countKeys()`, `findStoredTextsOfKeys()`, `getCompleteness()`, `findStoredText()`,
`setValue()`, `createKey()`, `findReferenceText()`.

## Server messages (text export)

`i18n/server-messages.json` lists the messages this package's endpoints answer with (user-facing exceptions, message
constants, refusal reasons), each with a hint for translators. A host merges it into its text export with
`vendor/bin/ddd-server-messages collect` (mgamadeus/ddd >= 3.42) and never prunes a key a package lists. After changing
a message: `php vendor/bin/ddd-server-messages build`, write the hint of a new key (at least 40 characters); CI runs
`build --check`.

## Argus endpoints

| Argus repo | Endpoint | Cache | Purpose |
|---|---|---|---|
| `ArgusTranslation` | `POST:/rc-translations/deepl/translate` | 1 day | DeepL translation |
| `ArgusTranslations` | `POST:/ai/openRouter/chatCompletions` | none | LLM batch translation |
| `ArgusTextEmbedding` | `POST:/ai/openRouter/embeddings` | 1 day | Text vectorization |
| `ArgusDetectedLanguage` | `POST:/ai/openRouter/chatCompletions` | none | Language detection |

All require `ARGUS_API_ENDPOINT` to be configured (see [ddd-argus](https://github.com/ddd-framework/ddd-argus)).

## Database tables

The AppTranslations system creates three tables:

- `AppTranslationKeys` — translation keys with metadata
- `AppTranslationValues` — per-key translations (unique on key + language + virtual country)
- `AppTranslationDefaultTerms` — glossary/terminology mappings
