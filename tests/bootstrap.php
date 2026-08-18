<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * The PHP suite runs without booting Craft. Almost everything in Live is deliberately Craft-shaped —
 * elements, element queries, project config — and faking those well enough to prove anything costs
 * more than it proves. What is covered here is the part that is plain PHP over plain data: the
 * settings model, the post state machine, and the sequence arithmetic. Everything else is exercised
 * against a real Craft install; see `tests/README.md` for what that run consists of.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Yii2 is not autoloadable on its own — Yii.php registers the class map, the DI container and the
// `Yii` alias. The models extend craft\base\Model, so their validators need it.
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

// `Craft` lives in the root namespace and is not covered by Craft's PSR-4 map, so it has to be
// required by hand. It extends Yii, and `Craft::t()` falls back to plain parameter substitution
// when there is no application — which is exactly what a unit test wants.
require_once __DIR__ . '/../vendor/craftcms/cms/src/Craft.php';

Craft::setAlias('@webroot', '/tmp/live-tests/webroot');
Craft::setAlias('@web', 'https://live.test');

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');
