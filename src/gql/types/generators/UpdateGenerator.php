<?php

namespace justinholtweb\live\gql\types\generators;

use Craft;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;
use justinholtweb\live\elements\Update;
use justinholtweb\live\gql\interfaces\UpdateInterface;
use justinholtweb\live\gql\types\UpdateGqlType;
use justinholtweb\live\Plugin;

/**
 * One GraphQL type per update type, so a Goal can expose its scorer field and a plain update can't.
 */
class UpdateGenerator extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        $types = [];

        foreach (Plugin::getInstance()->updateTypes->getAllTypes() as $updateType) {
            if (!GqlHelper::isSchemaAwareOf(Update::gqlScopesByContext($updateType))) {
                continue;
            }

            $type = static::generateType($updateType);
            $types[$type->name] = $type;
        }

        return $types;
    }

    public static function generateType(mixed $context): ObjectType
    {
        $typeName = Update::gqlTypeName($context);

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new UpdateGqlType([
            'name' => $typeName,
            'fields' => function() use ($context, $typeName) {
                $fields = array_merge(
                    UpdateInterface::getFieldDefinitions(),
                    self::getContentFields($context),
                );

                return Craft::$app->getGql()->prepareFieldDefinitions($fields, $typeName);
            },
        ]));
    }
}
