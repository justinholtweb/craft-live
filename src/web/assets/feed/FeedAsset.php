<?php

namespace justinholtweb\live\web\assets\feed;

use craft\web\AssetBundle;

/**
 * The front-end runtime: one custom element, no dependencies, no framework.
 *
 * Sites are welcome to skip this entirely and talk to `head.json` themselves — it is a documented,
 * stable shape. This bundle is here so that the common case is one line of Twig.
 */
class FeedAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $js = ['live-feed.js'];

    public $css = ['live-feed.css'];

    public $jsOptions = ['defer' => true];
}
