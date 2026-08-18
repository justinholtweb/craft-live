<?php

namespace justinholtweb\live\gql\types;

use craft\gql\types\elements\Element;
use GraphQL\Type\Definition\ResolveInfo;
use justinholtweb\live\elements\Update;
use justinholtweb\live\gql\interfaces\UpdateInterface;
use justinholtweb\live\Plugin;

/**
 * The concrete GraphQL type behind one update type.
 */
class UpdateGqlType extends Element
{
    public function __construct(array $config)
    {
        $config['interfaces'] = [
            UpdateInterface::getType(),
        ];

        parent::__construct($config);
    }

    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var Update $source */
        return match ($resolveInfo->fieldName) {
            'typeHandle' => $source->getType()->handle,
            'typeName' => $source->getType()->name,
            'color' => $source->getType()->color,
            'icon' => $source->getType()->icon,
            'rev' => (int)$source->metaValue('rev', 0),
            'source' => $source->getSource(),
            'excerpt' => $source->getExcerpt(),
            // Rendered on demand only: this costs a Twig render per update, and a headless client
            // that builds its own markup should never pay for it.
            'html' => Plugin::getInstance()->feeds->renderUpdate($source),
            'authorName' => $source->getAuthor()?->friendlyName,
            default => parent::resolve($source, $arguments, $context, $resolveInfo),
        };
    }
}
