<?php

namespace justinholtweb\live\gql\interfaces;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use justinholtweb\live\gql\types\generators\UpdateGenerator;

/**
 * The shape every live update has, whatever its type.
 */
class UpdateInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return UpdateGenerator::class;
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all live updates.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        UpdateGenerator::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'LiveUpdateInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), [
            'seq' => [
                'name' => 'seq',
                'type' => Type::nonNull(Type::int()),
                'description' => 'The update’s position in its feed. Monotonic per post, never reused — ask for everything after the highest one you hold.',
            ],
            'rev' => [
                'name' => 'rev',
                'type' => Type::nonNull(Type::int()),
                'description' => 'Bumped whenever the update is edited, so a client holding an older copy knows to replace it.',
            ],
            'postId' => [
                'name' => 'postId',
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the element this update belongs to.',
            ],
            'fieldId' => [
                'name' => 'fieldId',
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the Live field this update was posted through.',
            ],
            'typeHandle' => [
                'name' => 'typeHandle',
                'type' => Type::nonNull(Type::string()),
                'description' => 'The handle of the update’s type.',
            ],
            'typeName' => [
                'name' => 'typeName',
                'type' => Type::nonNull(Type::string()),
                'description' => 'The name of the update’s type.',
            ],
            'color' => [
                'name' => 'color',
                'type' => Type::string(),
                'description' => 'The colour assigned to the update’s type.',
            ],
            'icon' => [
                'name' => 'icon',
                'type' => Type::string(),
                'description' => 'The icon assigned to the update’s type.',
            ],
            'body' => [
                'name' => 'body',
                'type' => Type::string(),
                'description' => 'The update’s text, as purified HTML.',
            ],
            'source' => [
                'name' => 'source',
                'type' => Type::string(),
                'description' => 'What the editor typed, before it became HTML.',
            ],
            'excerpt' => [
                'name' => 'excerpt',
                'type' => Type::string(),
                'description' => 'A plain-text summary of the update.',
            ],
            'html' => [
                'name' => 'html',
                'type' => Type::string(),
                'description' => 'The update rendered through the site’s own Twig partial. Only rendered when asked for.',
            ],
            'pinned' => [
                'name' => 'pinned',
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether this update is pinned to the top of its feed.',
            ],
            'highlight' => [
                'name' => 'highlight',
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether this update is flagged as a key moment.',
            ],
            'postedAt' => [
                'name' => 'postedAt',
                'type' => \craft\gql\types\DateTime::getType(),
                'description' => 'When the update was posted.',
            ],
            'authorId' => [
                'name' => 'authorId',
                'type' => Type::int(),
                'description' => 'The ID of the user who posted the update.',
            ],
            'authorName' => [
                'name' => 'authorName',
                'type' => Type::string(),
                'description' => 'The name of the user who posted the update.',
            ],
        ]), self::getName());
    }
}
