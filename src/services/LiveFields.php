<?php

namespace justinholtweb\live\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use justinholtweb\live\fields\LiveField;

/**
 * Finding the Live field on an element, and the other way round.
 */
class LiveFields extends Component
{
    /** @var LiveField[]|null */
    private ?array $_fields = null;

    /**
     * Every Live field in the install.
     *
     * @return LiveField[]
     */
    public function getAllFields(): array
    {
        if ($this->_fields !== null) {
            return $this->_fields;
        }

        $this->_fields = array_values(array_filter(
            Craft::$app->getFields()->getAllFields(),
            fn($field) => $field instanceof LiveField,
        ));

        return $this->_fields;
    }

    public function getFieldById(int $id): ?LiveField
    {
        foreach ($this->getAllFields() as $field) {
            if ((int)$field->id === $id) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The Live field on an element's own field layout — the one that makes it a live post.
     *
     * An element can carry more than one (a match report with a commentary feed and a separate
     * ticker), so the first is only a default: callers that know which one they want pass its ID.
     */
    public function getFieldForElement(ElementInterface $element): ?LiveField
    {
        $layout = $element->getFieldLayout();

        if (!$layout) {
            return null;
        }

        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof LiveField) {
                return $field;
            }
        }

        return null;
    }

    public function getFieldIdForElement(ElementInterface $element): ?int
    {
        $field = $this->getFieldForElement($element);

        return $field?->id ? (int)$field->id : null;
    }

    /**
     * @return LiveField[]
     */
    public function getFieldsForElement(ElementInterface $element): array
    {
        $layout = $element->getFieldLayout();

        if (!$layout) {
            return [];
        }

        return array_values(array_filter(
            $layout->getCustomFields(),
            fn($field) => $field instanceof LiveField,
        ));
    }

    public function flushCache(): void
    {
        $this->_fields = null;
    }
}
