<?php

namespace justinholtweb\live\gql\resolvers;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\ElementCollection;
use craft\gql\base\ElementResolver;
use craft\helpers\Gql as GqlHelper;
use justinholtweb\live\elements\Update;
use justinholtweb\live\Plugin;
use yii\base\UnknownMethodException;

/**
 * Turns GraphQL arguments into an update query, and holds the schema's boundaries.
 */
class UpdateResolver extends ElementResolver
{
    public static function prepareQuery(mixed $source, array $arguments, ?string $fieldName = null): mixed
    {
        if ($source === null) {
            $query = Update::find();
        } else {
            $query = $source->$fieldName;
        }

        if (!$query instanceof ElementQuery) {
            return $query;
        }

        foreach ($arguments as $key => $value) {
            try {
                $query->$key($value);
            } catch (UnknownMethodException $e) {
                if ($value !== null) {
                    throw $e;
                }
            }
        }

        $allowedIds = self::allowedTypeIds();

        if ($allowedIds === []) {
            return ElementCollection::empty();
        }

        $query->andWhere(['in', 'live_updates.typeId', $allowedIds]);

        return $query;
    }

    /**
     * The update type IDs this schema may read. An empty array means none at all — which is a
     * different answer from “no filter”, and getting that backwards would publish every feed on the
     * site to a token that was granted one.
     *
     * @return int[]
     */
    public static function allowedTypeIds(): array
    {
        $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read');

        if (!isset($pairs['liveupdatetypes'])) {
            return [];
        }

        $service = Plugin::getInstance()->updateTypes;

        return array_values(array_filter(array_map(
            fn(string $uid) => $service->getTypeByUid($uid)?->id,
            $pairs['liveupdatetypes'],
        )));
    }
}
