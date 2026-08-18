<?php

namespace justinholtweb\live\gql\types;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime as DateTimeType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use justinholtweb\live\elements\Update;
use justinholtweb\live\gql\arguments\UpdateArguments;
use justinholtweb\live\gql\interfaces\UpdateInterface;
use justinholtweb\live\gql\resolvers\UpdateResolver;
use justinholtweb\live\models\LiveFeed;
use justinholtweb\live\models\LivePost;

/**
 * The head of a feed: where it is in its life cycle, and how far its sequence has run.
 *
 * This is the query a headless front end polls. It is deliberately cheap — one indexed row — so a
 * client can ask “anything new?” every few seconds without pulling the feed each time.
 */
class LiveFeedGqlType
{
    public static function getName(): string
    {
        return 'LiveFeed';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'The state and sequence of one live post.',
            'fields' => fn() => [
                'postId' => [
                    'name' => 'postId',
                    'type' => Type::nonNull(Type::int()),
                ],
                'fieldId' => [
                    'name' => 'fieldId',
                    'type' => Type::nonNull(Type::int()),
                ],
                'siteId' => [
                    'name' => 'siteId',
                    'type' => Type::nonNull(Type::int()),
                ],
                'seq' => [
                    'name' => 'seq',
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'The highest sequence number reached. Poll this; fetch updates only when it moves.',
                ],
                'state' => [
                    'name' => 'state',
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'One of upcoming, live, paused or ended.',
                ],
                'isLive' => [
                    'name' => 'isLive',
                    'type' => Type::nonNull(Type::boolean()),
                ],
                'isFollowable' => [
                    'name' => 'isFollowable',
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Whether a reader should still be watching. False once the post has ended.',
                ],
                'count' => [
                    'name' => 'count',
                    'type' => Type::nonNull(Type::int()),
                ],
                'pinnedId' => [
                    'name' => 'pinnedId',
                    'type' => Type::int(),
                ],
                'startedAt' => [
                    'name' => 'startedAt',
                    'type' => DateTimeType::getType(),
                ],
                'endedAt' => [
                    'name' => 'endedAt',
                    'type' => DateTimeType::getType(),
                ],
                'updates' => [
                    'name' => 'updates',
                    'type' => Type::listOf(UpdateInterface::getType()),
                    'args' => UpdateArguments::getArguments(),
                    'description' => 'The updates in this feed.',
                    'resolve' => self::class . '::resolveUpdates',
                ],
            ],
            'resolveField' => self::class . '::resolveField',
        ]));
    }

    /**
     * Accept either shape.
     *
     * The root `liveFeed` query hands over a head row; a Live field on an entry hands over its
     * `LiveFeed` value, and that feed may have no head row at all yet — an entry that is set up for
     * live posting but hasn't been posted to. That is a real state with real answers (sequence 0,
     * upcoming, no updates), not a null.
     */
    private static function toPost(mixed $source): ?LivePost
    {
        if ($source instanceof LivePost) {
            return $source;
        }

        if ($source instanceof LiveFeed) {
            return $source->getPost() ?? new LivePost([
                'postId' => (int)($source->owner?->id ?? 0),
                'fieldId' => (int)($source->field?->id ?? 0),
                'siteId' => (int)($source->owner?->siteId ?? 0),
            ]);
        }

        return null;
    }

    public static function resolveField(mixed $source, array $arguments, mixed $context, $resolveInfo): mixed
    {
        $source = self::toPost($source);

        if (!$source) {
            return null;
        }

        return match ($resolveInfo->fieldName) {
            'isLive' => $source->getIsLive(),
            'isFollowable' => $source->getIsFollowable(),
            'count' => (int)$source->updateCount,
            'pinnedId' => $source->pinnedUpdateId,
            default => $source->{$resolveInfo->fieldName} ?? null,
        };
    }

    public static function resolveUpdates(mixed $source, array $arguments, mixed $context, $resolveInfo): mixed
    {
        $source = self::toPost($source);

        if (!$source || !$source->postId) {
            return [];
        }

        $arguments['postId'] = $source->postId;
        $arguments['fieldId'] = $source->fieldId;
        $arguments['siteId'] ??= $source->siteId;
        $arguments['status'] ??= Update::STATUS_PUBLISHED;

        return UpdateResolver::resolve(null, $arguments, $context, $resolveInfo);
    }
}
