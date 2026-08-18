<?php

namespace justinholtweb\live\events;

use justinholtweb\live\elements\Update;
use justinholtweb\live\models\LivePost;
use yii\base\Event;

/**
 * Raised around every change to a live feed.
 */
class UpdateEvent extends Event
{
    public ?Update $update = null;
    public ?LivePost $post = null;
    public bool $isNew = false;

    /** Set false in a before-event to stop the publish. */
    public bool $isValid = true;
}
