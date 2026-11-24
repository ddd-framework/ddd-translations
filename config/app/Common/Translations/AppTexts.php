<?php

declare(strict_types=1);

/**
 * The UI text keys as people maintain them (config `Common.Translations.AppTexts`, read by
 * {@see \DDD\Domain\Common\Services\AppTranslations\AppTranslationsMaintenanceService}).
 *
 * `areas`: the places keys are shown in, by name. No column stores the area: the start of the key string does. One
 * area without `keyPrefix` holds every key no other area claims. Per area:
 * - `label`: what the area is;
 * - `keyPrefix`: the start of every key of the area (`mobile.`); left out for the remainder area;
 * - `isSyncedFromCode`: the keys and their English texts come from the code (never created or given another English
 *   text by hand);
 * - `writingStyles`: the writing styles the area keeps (`['INFORMAL']`); left out: those the language is written in;
 * - `liveNote`: when a changed text is shown.
 *
 * An application with an app or a web of its own replaces this file, e.g.:
 *
 *   'areas' => [
 *       'APP' => ['label' => 'the mobile app', 'keyPrefix' => 'mobile.', 'isSyncedFromCode' => true,
 *                 'writingStyles' => ['INFORMAL'], 'liveNote' => 'The app shows the new text after it loads its texts again.'],
 *       'WEB' => ['label' => 'the admin web', 'keyPrefix' => 'web.', 'isSyncedFromCode' => true,
 *                 'liveNote' => 'The web shows the new text after a reload.'],
 *       'SERVER' => ['label' => 'mails, push and error texts', 'liveNote' => 'Server texts are cached: the new text is used within an hour.'],
 *   ],
 */
return [
    'areas' => [
        'SERVER' => [
            'label' => 'texts the server renders: mails, push and error texts',
            'liveNote' => 'Server texts are cached: the new text is used within an hour.',
        ],
    ],
];
