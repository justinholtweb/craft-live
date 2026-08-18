<?php

namespace justinholtweb\live\web\assets\composer;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The composer's own JavaScript. No dependencies beyond Craft's own CP bundle.
 */
class ComposerAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = ['live-composer.js'];

    public $css = ['live-composer.css'];
}
