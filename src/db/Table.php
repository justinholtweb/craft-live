<?php

namespace justinholtweb\live\db;

/**
 * Every table Live owns, in one place.
 */
abstract class Table
{
    public const UPDATES = '{{%live_updates}}';
    public const POSTS = '{{%live_posts}}';
    public const UPDATETYPES = '{{%live_updatetypes}}';
}
