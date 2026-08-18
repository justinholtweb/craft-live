<?php

namespace justinholtweb\live\models;

use Craft;
use craft\base\FieldLayoutProviderInterface;
use craft\base\Model;
use craft\behaviors\FieldLayoutBehavior;
use craft\enums\Color;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use justinholtweb\live\elements\Update;
use justinholtweb\live\records\UpdateTypeRecord;

/**
 * A kind of update — “Text”, “Goal”, “Photo”, “Quote”, “Score change”.
 *
 * Each type carries its own field layout, so a goal can ask for a scorer and a minute while a plain
 * text update asks for nothing but a body. The composer shows one button per type, which is what
 * makes single-keystroke posting possible: the editor has already said what kind of thing this is.
 *
 * @mixin FieldLayoutBehavior
 */
class UpdateType extends Model implements FieldLayoutProviderInterface
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $handle = null;
    public ?string $icon = null;
    public ?string $color = null;
    public ?string $description = null;

    /** Whether editors can give updates of this type a headline. */
    public bool $hasTitleField = false;

    /** Object template used when {@see $hasTitleField} is off. */
    public ?string $titleFormat = null;

    /** Whether the composer offers this type. Off for types only created programmatically. */
    public bool $showsInComposer = true;

    public ?int $sortOrder = null;
    public ?int $fieldLayoutId = null;
    public ?string $uid = null;

    public function behaviors(): array
    {
        return [
            'fieldLayout' => [
                'class' => FieldLayoutBehavior::class,
                'elementType' => Update::class,
            ],
        ];
    }

    public function __toString(): string
    {
        return (string)$this->handle ?: static::class;
    }

    public function getHandle(): ?string
    {
        return $this->handle;
    }

    public function getFieldLayout(): FieldLayout
    {
        /** @var FieldLayoutBehavior $behavior */
        $behavior = $this->getBehavior('fieldLayout');

        return $behavior->getFieldLayout();
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid', 'title']],
            [['handle'], UniqueValidator::class, 'targetClass' => UpdateTypeRecord::class],
            [['color'], 'in', 'range' => array_map(fn(Color $case) => $case->value, Color::cases()), 'skipOnEmpty' => true],
            [['hasTitleField', 'showsInComposer'], 'boolean'],
            [['titleFormat', 'icon', 'description'], 'string'],
            [['sortOrder', 'fieldLayoutId'], 'integer'],
        ];
    }

    /**
     * The label the composer shows on this type's button.
     */
    public function getComposerLabel(): string
    {
        return Craft::t('site', $this->name ?? '');
    }

    public function getColorEnum(): ?Color
    {
        return $this->color ? Color::tryFrom($this->color) : null;
    }

    /**
     * Project config representation. Field layouts go in as a uid-keyed hash the same way entry
     * types write theirs, so a layout survives a config rebuild on another environment.
     */
    public function getConfig(): array
    {
        $config = [
            'name' => $this->name,
            'handle' => $this->handle,
            'icon' => $this->icon ?: null,
            'color' => $this->color ?: null,
            'description' => $this->description ?: null,
            'hasTitleField' => $this->hasTitleField,
            'titleFormat' => $this->titleFormat ?: null,
            'showsInComposer' => $this->showsInComposer,
            'sortOrder' => (int)($this->sortOrder ?? 0),
        ];

        $fieldLayout = $this->getFieldLayout();
        $fieldLayoutConfig = $fieldLayout->getConfig();

        if ($fieldLayoutConfig) {
            if (!$fieldLayout->uid) {
                $fieldLayout->uid = $fieldLayout->id
                    ? Craft::$app->getFields()->getLayoutById($fieldLayout->id)?->uid ?? StringHelper::UUID()
                    : StringHelper::UUID();
            }
            $config['fieldLayouts'] = [$fieldLayout->uid => $fieldLayoutConfig];
        }

        return $config;
    }
}
