<?php

namespace justinholtweb\live\gql\arguments;

use craft\gql\base\ElementArguments;
use GraphQL\Type\Definition\Type;

/**
 * Everything you can narrow a live feed by.
 */
class UpdateArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), static::getContentArguments(), [
            'postId' => [
                'name' => 'postId',
                'type' => Type::listOf(Type::int()),
                'description' => 'Narrow to updates on a given element.',
            ],
            'fieldId' => [
                'name' => 'fieldId',
                'type' => Type::listOf(Type::int()),
                'description' => 'Narrow to updates posted through a given Live field.',
            ],
            'type' => [
                'name' => 'type',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrow to one or more update type handles.',
            ],
            'typeId' => [
                'name' => 'typeId',
                'type' => Type::listOf(Type::int()),
                'description' => 'Narrow to one or more update type IDs.',
            ],
            'seq' => [
                'name' => 'seq',
                'type' => Type::listOf(Type::int()),
                'description' => 'Narrow to specific sequence numbers.',
            ],
            'since' => [
                'name' => 'since',
                'type' => Type::int(),
                'description' => 'Only updates published after this sequence number — the polling query.',
            ],
            'before' => [
                'name' => 'before',
                'type' => Type::int(),
                'description' => 'Only updates below this sequence number — loading older ones.',
            ],
            'pinned' => [
                'name' => 'pinned',
                'type' => Type::boolean(),
                'description' => 'Narrow to the pinned update, or to everything but it.',
            ],
            'highlight' => [
                'name' => 'highlight',
                'type' => Type::boolean(),
                'description' => 'Narrow to key moments.',
            ],
            'authorId' => [
                'name' => 'authorId',
                'type' => Type::listOf(Type::int()),
                'description' => 'Narrow to updates by a given author.',
            ],
        ]);
    }

    public static function getContentArguments(): array
    {
        return [];
    }
}
