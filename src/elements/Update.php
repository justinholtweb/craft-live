<?php

namespace justinholtweb\live\elements;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use DateTime;
use justinholtweb\live\db\Table;
use GraphQL\Type\Definition\Type as GqlType;
use justinholtweb\live\elements\db\UpdateQuery;
use justinholtweb\live\gql\interfaces\UpdateInterface;
use justinholtweb\live\models\UpdateType;
use justinholtweb\live\Plugin;
use Twig\Markup;
use yii\base\InvalidConfigException;

/**
 * One entry in a live blog.
 *
 * The reason this is its own element type rather than a nested entry is speed. A publish is the
 * thing an editor does forty times an hour with a match going on in front of them, so it has to
 * cost as little as possible: no revision, no provisional draft, no search-index pass, no touching
 * the parent entry at all. What is kept is everything that makes it Craft — a field layout per
 * update type, relations, assets, rich text, permissions, soft deletes and restore.
 *
 * Ordering is by `seq`, a per-post counter allocated under a row lock at publish time, not by date.
 * Two updates published in the same second still have a defined, stable order, and a reader that
 * knows it has seen up to seq 412 can ask for “everything after 412” with no clock skew anywhere in
 * the conversation.
 *
 * @property-read UpdateType $type
 * @property-read ElementInterface|null $post
 */
class Update extends Element
{
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SCHEDULED = 'scheduled';

    /** The owner element — the entry the Live field lives on. */
    public ?int $postId = null;

    /** Which Live field this came from; an entry may carry more than one. */
    public ?int $fieldId = null;

    public ?int $typeId = null;

    /** Per-post monotonic sequence. Assigned by the publisher; never reused. */
    public ?int $seq = null;

    /** The update's main text, as purified HTML. */
    public ?string $body = null;

    public ?DateTime $postedAt = null;
    public bool $pinned = false;
    public bool $highlight = false;
    public ?int $authorId = null;

    /** Idempotency key sent by the composer so a retried publish can't double-post. */
    public ?string $clientId = null;

    private array $_meta = [];
    private ?UpdateType $_type = null;
    private ElementInterface|false|null $_post = null;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('live', 'Update');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('live', 'update');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('live', 'Updates');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('live', 'updates');
    }

    public static function refHandle(): ?string
    {
        return 'liveupdate';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PUBLISHED => ['label' => Craft::t('live', 'Published'), 'color' => Color::Green],
            self::STATUS_SCHEDULED => ['label' => Craft::t('live', 'Scheduled'), 'color' => Color::Orange],
            self::STATUS_DISABLED => ['label' => Craft::t('live', 'Held'), 'color' => Color::Gray],
        ];
    }

    /**
     * Updates exist on exactly one site — the one they were posted on — but that site can be any
     * site, which is what `isLocalized()` unlocks. A newsroom running six outlets covers the same
     * match six times, not once with five translations.
     */
    public static function isLocalized(): bool
    {
        return true;
    }

    public function getSupportedSites(): array
    {
        return [$this->siteId];
    }

    public static function trackChanges(): bool
    {
        return false;
    }

    public static function find(): ElementQueryInterface
    {
        return new UpdateQuery(static::class);
    }

    public function getStatus(): ?string
    {
        $status = parent::getStatus();

        if ($status !== self::STATUS_ENABLED) {
            return $status;
        }

        if ($this->postedAt && $this->postedAt->getTimestamp() > time()) {
            return self::STATUS_SCHEDULED;
        }

        return self::STATUS_PUBLISHED;
    }

    /** Whether this update is on the site right now. */
    public function getIsPublished(): bool
    {
        return $this->getStatus() === self::STATUS_PUBLISHED;
    }

    // Type, owner, author
    // -------------------------------------------------------------------------

    public function getType(): UpdateType
    {
        if ($this->_type !== null) {
            return $this->_type;
        }

        if (!$this->typeId) {
            throw new InvalidConfigException('Update is missing its type ID.');
        }

        $type = Plugin::getInstance()->updateTypes->getTypeById($this->typeId);

        if (!$type) {
            throw new InvalidConfigException("Invalid update type ID: $this->typeId");
        }

        return $this->_type = $type;
    }

    public function setType(UpdateType $type): void
    {
        $this->_type = $type;
        $this->typeId = $type->id;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        try {
            return $this->getType()->getFieldLayout();
        } catch (InvalidConfigException) {
            return null;
        }
    }

    /**
     * The entry (or other element) this update belongs to.
     */
    public function getPost(): ?ElementInterface
    {
        if ($this->_post !== null) {
            return $this->_post ?: null;
        }

        if (!$this->postId) {
            return null;
        }

        $post = Craft::$app->getElements()->getElementById($this->postId, null, $this->siteId);

        $this->_post = $post ?? false;

        return $post;
    }

    public function setPost(?ElementInterface $post): void
    {
        $this->_post = $post ?? false;
        $this->postId = $post?->id;
    }

    public function getAuthor(): ?User
    {
        return $this->authorId ? Craft::$app->getUsers()->getUserById($this->authorId) : null;
    }

    // Meta
    // -------------------------------------------------------------------------

    /**
     * Plugin-internal extras — never user content, which belongs in the field layout. Used for
     * things like the embed provider a URL resolved to, or an editing trail.
     */
    public function getMeta(): array
    {
        return $this->_meta;
    }

    public function setMeta(mixed $value): void
    {
        if (is_string($value)) {
            $value = json_decode($value, true) ?: [];
        }

        $this->_meta = is_array($value) ? $value : [];
    }

    /**
     * What the editor actually typed, before it became HTML.
     *
     * Kept so that editing an update reopens the text they wrote rather than the markup it turned
     * into — reformatting somebody's correction into `<p>` tags in front of them is a good way to
     * lose their trust in the box.
     */
    public function getSource(): ?string
    {
        return $this->metaValue('src');
    }

    public function setSource(?string $text): void
    {
        $meta = $this->getMeta();

        if ($text === null || trim($text) === '') {
            unset($meta['src']);
            $this->body = null;
        } else {
            $meta['src'] = $text;
            $this->body = Plugin::getInstance()->feeds->renderMarkdown($text);
        }

        $this->setMeta($meta);
    }

    public function metaValue(string $key, mixed $default = null): mixed
    {
        return $this->_meta[$key] ?? $default;
    }

    // Presentation
    // -------------------------------------------------------------------------

    public function getUiLabel(): string
    {
        if ($this->title) {
            return $this->title;
        }

        $excerpt = $this->getExcerpt(60);

        if ($excerpt !== '') {
            return $excerpt;
        }

        return Craft::t('live', 'Update {seq}', ['seq' => $this->seq ?? '']);
    }

    /**
     * First run of readable text in the update, for CP labels, page titles and snapshot summaries.
     */
    public function getExcerpt(int $length = 140): string
    {
        if ($this->body !== null && trim(strip_tags($this->body)) !== '') {
            return StringHelper::safeTruncate(trim(strip_tags($this->body)), $length, '…');
        }

        $layout = $this->getFieldLayout();

        if (!$layout) {
            return '';
        }

        foreach ($layout->getCustomFields() as $field) {
            try {
                $value = $this->getFieldValue($field->handle);
            } catch (\Throwable) {
                continue;
            }

            $text = trim(strip_tags((string)(is_object($value) ? (string)$value : (is_scalar($value) ? $value : ''))));

            if ($text !== '') {
                return StringHelper::safeTruncate($text, $length, '…');
            }
        }

        return '';
    }

    /**
     * The body, ready to print. Already purified on save, so it is safe to echo.
     */
    public function getBodyHtml(): Markup
    {
        return new Markup((string)$this->body, Craft::$app->charset);
    }

    protected function uiLabel(): ?string
    {
        return $this->getUiLabel();
    }

    public function getCpEditUrl(): ?string
    {
        $post = $this->getPost();

        if (!$post?->id) {
            return null;
        }

        return UrlHelper::cpUrl("live/post/$post->id", [
            'fieldId' => $this->fieldId,
            'site' => $this->getSite()->handle,
        ]) . "#update-$this->id";
    }

    /**
     * A coloured dot per update type, so a feed of forty updates reads at a glance.
     */
    public function getChipLabelHtml(): string
    {
        $type = null;

        try {
            $type = $this->getType();
        } catch (InvalidConfigException) {
        }

        $label = parent::getChipLabelHtml();

        if (!$type?->color) {
            return $label;
        }

        return Html::tag('span', '', [
            'class' => ['live-type-dot', "live-type-dot--$type->color"],
            'title' => $type->name,
        ]) . $label;
    }

    // GraphQL
    // -------------------------------------------------------------------------

    /**
     * A type per update type, so a Goal exposes its scorer field and a plain update does not.
     */
    public static function gqlTypeName(UpdateType $updateType): string
    {
        return sprintf('%s_LiveUpdate', $updateType->handle);
    }

    public static function gqlTypeNameByContext(mixed $context): string
    {
        /** @var UpdateType $context */
        return self::gqlTypeName($context);
    }

    public static function gqlScopesByContext(mixed $context): array
    {
        /** @var UpdateType $context */
        return ["liveupdatetypes.$context->uid"];
    }

    public static function baseGqlType(): GqlType
    {
        return UpdateInterface::getType();
    }

    /**
     * Without this the interface resolves an update to the type name “Update” — the short class
     * name from the base implementation — which is not a type anybody registered, and every query
     * that reaches an update fails with an internal error.
     */
    public function getGqlTypeName(): string
    {
        return self::gqlTypeName($this->getType());
    }

    // Caching
    // -------------------------------------------------------------------------

    /**
     * Element-query caches keyed per post, so publishing to one live blog cannot invalidate
     * another's cached queries.
     */
    public function getCacheTags(): array
    {
        return array_filter([
            'live',
            $this->postId ? "live:post:$this->postId" : null,
            $this->typeId ? "live:type:$this->typeId" : null,
        ]);
    }

    // Validation and saving
    // -------------------------------------------------------------------------

    protected function shouldValidateTitle(): bool
    {
        try {
            return $this->getType()->hasTitleField;
        } catch (InvalidConfigException) {
            return false;
        }
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['postId', 'fieldId', 'typeId', 'siteId'], 'required'];
        $rules[] = [['postId', 'fieldId', 'typeId', 'seq', 'authorId'], 'integer'];
        $rules[] = [['pinned', 'highlight'], 'boolean'];
        $rules[] = [['clientId'], 'string', 'max' => 64];
        $rules[] = [['body'], 'string'];
        // skipOnEmpty off: an empty body is precisely the case this rule exists to catch.
        $rules[] = [['body'], 'validateHasSomething', 'skipOnEmpty' => false];

        return $rules;
    }

    /**
     * An update with neither text nor a single filled-in field is an editor hitting publish by
     * accident, and it lands on the live site as a blank card. Reject it here rather than in the
     * composer alone, because the composer is not the only thing that publishes.
     */
    public function validateHasSomething(): void
    {
        if ($this->title || trim(strip_tags((string)$this->body)) !== '') {
            return;
        }

        $layout = $this->getFieldLayout();

        foreach ($layout?->getCustomFields() ?? [] as $field) {
            if (!$field->isValueEmpty($this->getFieldValue($field->handle), $this)) {
                return;
            }
        }

        $this->addError('body', Craft::t('live', 'There’s nothing in this update.'));
    }

    public function beforeSave(bool $isNew): bool
    {
        if (!parent::beforeSave($isNew)) {
            return false;
        }

        $this->postedAt ??= new DateTime();

        if (!$this->title && !$this->getType()->hasTitleField && $this->getType()->titleFormat) {
            $this->title = Craft::$app->getView()->renderObjectTemplate(
                $this->getType()->titleFormat,
                $this,
                ['element' => $this],
            );
        }

        return true;
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            Db::upsert(Table::UPDATES, [
                'id' => $this->id,
                'postId' => $this->postId,
                'fieldId' => $this->fieldId,
                'siteId' => $this->siteId,
                'typeId' => $this->typeId,
                'seq' => $this->seq,
                'body' => $this->body,
                'postedAt' => Db::prepareDateForDb($this->postedAt),
                'pinned' => $this->pinned,
                'highlight' => $this->highlight,
                'authorId' => $this->authorId,
                'clientId' => $this->clientId,
                'meta' => $this->_meta ? Db::prepareValueForDb($this->_meta) : null,
            ]);
        }

        parent::afterSave($isNew);
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW);
    }

    public function canSave(User $user): bool
    {
        if ($this->authorId && $this->authorId !== $user->id) {
            return $user->can(Plugin::PERMISSION_EDIT_OTHERS);
        }

        return $user->can(Plugin::PERMISSION_PUBLISH);
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE);
    }

    public function canDuplicate(User $user): bool
    {
        return false;
    }

    public function canCreateDrafts(User $user): bool
    {
        return false;
    }
}
