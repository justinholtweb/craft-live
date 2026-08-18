<?php

namespace justinholtweb\live\gql\queries;

use Craft;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\live\gql\arguments\UpdateArguments;
use justinholtweb\live\gql\interfaces\UpdateInterface;
use justinholtweb\live\gql\resolvers\UpdateResolver;
use justinholtweb\live\gql\types\LiveFeedGqlType;
use justinholtweb\live\Plugin;

/**
 * Live's root queries.
 */
class UpdateQueries extends Query
{
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !self::canQueryUpdates()) {
            return [];
        }

        return [
            'liveUpdates' => [
                'type' => Type::listOf(UpdateInterface::getType()),
                'args' => UpdateArguments::getArguments(),
                'resolve' => UpdateResolver::class . '::resolve',
                'description' => 'Query for live updates.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
            'liveUpdate' => [
                'type' => UpdateInterface::getType(),
                'args' => UpdateArguments::getArguments(),
                'resolve' => UpdateResolver::class . '::resolveOne',
                'description' => 'Query for a single live update.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
            'liveUpdateCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => UpdateArguments::getArguments(),
                'resolve' => UpdateResolver::class . '::resolveCount',
                'description' => 'Count live updates.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
            'liveFeed' => [
                'type' => LiveFeedGqlType::getType(),
                'args' => [
                    'postId' => Type::nonNull(Type::int()),
                    'fieldId' => Type::int(),
                    'siteId' => Type::int(),
                ],
                'resolve' => self::class . '::resolveFeed',
                'description' => 'The state and sequence number of one live post — the cheap “anything new?” query.',
            ],
        ];
    }

    /**
     * Resolve the head row for a post.
     *
     * The field ID is optional because most entries carry exactly one Live field, and making every
     * client look it up first would be pointless ceremony.
     */
    public static function resolveFeed(mixed $source, array $arguments): mixed
    {
        $postId = (int)$arguments['postId'];
        $siteId = (int)($arguments['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id);
        $fieldId = isset($arguments['fieldId']) ? (int)$arguments['fieldId'] : null;

        if (!$fieldId) {
            $owner = Craft::$app->getElements()->getElementById($postId, null, $siteId);

            if (!$owner) {
                return null;
            }

            $fieldId = Plugin::getInstance()->fields->getFieldIdForElement($owner);
        }

        if (!$fieldId) {
            return null;
        }

        $post = Plugin::getInstance()->posts->getPost($postId, $fieldId, $siteId);

        if (!$post) {
            return null;
        }

        // A feed is only as public as its schema: a token that can't read any of the update types on
        // this post shouldn't learn its state either.
        if (UpdateResolver::allowedTypeIds() === []) {
            return null;
        }

        return $post;
    }

    public static function canQueryUpdates(): bool
    {
        $allowed = GqlHelper::extractAllowedEntitiesFromSchema();

        return isset($allowed['liveupdatetypes']);
    }
}
