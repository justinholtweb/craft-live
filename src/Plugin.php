<?php

namespace justinholtweb\live;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Fields;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\services\Gc;
use craft\services\Gql;
use craft\services\ProjectConfig;
use craft\events\RegisterTemplateRootsEvent;
use craft\services\UserPermissions;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\live\elements\Update;
use justinholtweb\live\fields\LiveField;
use justinholtweb\live\gql\interfaces\UpdateInterface;
use justinholtweb\live\gql\queries\UpdateQueries;
use justinholtweb\live\models\Settings;
use justinholtweb\live\services\Feeds;
use justinholtweb\live\services\LiveFields;
use justinholtweb\live\services\Posts;
use justinholtweb\live\services\Presence;
use justinholtweb\live\services\Publisher;
use justinholtweb\live\services\Purger;
use justinholtweb\live\services\Snapshots;
use justinholtweb\live\services\UpdateTypes;
use justinholtweb\live\twig\LiveVariable;
use yii\base\Event;

/**
 * Live — post the minute it happens.
 *
 * @property-read Posts $posts
 * @property-read Publisher $publisher
 * @property-read UpdateTypes $updateTypes
 * @property-read Snapshots $snapshots
 * @property-read Feeds $feeds
 * @property-read LiveFields $fields
 * @property-read Presence $presence
 * @property-read Purger $purger
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'live:viewUpdates';
    public const PERMISSION_PUBLISH = 'live:publishUpdates';
    public const PERMISSION_EDIT_OTHERS = 'live:editOtherUpdates';
    public const PERMISSION_DELETE = 'live:deleteUpdates';
    public const PERMISSION_CONTROL = 'live:controlPosts';
    public const PERMISSION_MANAGE_TYPES = 'live:manageUpdateTypes';

    public const LOG_CATEGORY = 'live';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'posts' => Posts::class,
                'publisher' => Publisher::class,
                'updateTypes' => UpdateTypes::class,
                'snapshots' => Snapshots::class,
                'feeds' => Feeds::class,
                'fields' => LiveFields::class,
                'presence' => Presence::class,
                'purger' => Purger::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementTypes();
        $this->registerFieldTypes();
        $this->registerProjectConfigHandlers();
        $this->registerPermissions();
        $this->registerCpRoutes();
        $this->registerTwig();
        $this->registerTemplateRoots();
        $this->registerGarbageCollection();
        $this->registerGraphQl();
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('live/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('live', 'Live');

        $item['subnav']['posts'] = [
            'label' => Craft::t('live', 'Posts'),
            'url' => 'live/posts',
        ];

        if (Craft::$app->getUser()->checkPermission(self::PERMISSION_MANAGE_TYPES)) {
            $item['subnav']['types'] = [
                'label' => Craft::t('live', 'Update Types'),
                'url' => 'live/types',
            ];
        }

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('live', 'Settings'),
                'url' => 'settings/plugins/live',
            ];
        }

        return $item;
    }

    // Registration
    // -------------------------------------------------------------------------

    private function registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = Update::class;
            },
        );
    }

    private function registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = LiveField::class;
            },
        );

        // A changed field layout can change which fields a Live field's updates carry.
        Event::on(Fields::class, Fields::EVENT_AFTER_SAVE_FIELD, fn() => $this->fields->flushCache());
        Event::on(Fields::class, Fields::EVENT_AFTER_DELETE_FIELD, fn() => $this->fields->flushCache());
    }

    private function registerProjectConfigHandlers(): void
    {
        Craft::$app->getProjectConfig()
            ->onAdd(UpdateTypes::CONFIG_KEY . '.{uid}', [$this->updateTypes, 'handleChangedType'])
            ->onUpdate(UpdateTypes::CONFIG_KEY . '.{uid}', [$this->updateTypes, 'handleChangedType'])
            ->onRemove(UpdateTypes::CONFIG_KEY . '.{uid}', [$this->updateTypes, 'handleDeletedType']);

        Event::on(
            ProjectConfig::class,
            ProjectConfig::EVENT_REBUILD,
            function(\craft\events\RebuildConfigEvent $event) {
                $event->config['live']['updateTypes'] = [];

                foreach ($this->updateTypes->getAllTypes() as $type) {
                    $event->config['live']['updateTypes'][$type->uid] = $type->getConfig();
                }
            },
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('live', 'Live'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('live', 'View live posts'),
                            'nested' => [
                                self::PERMISSION_PUBLISH => [
                                    'label' => Craft::t('live', 'Publish updates'),
                                    'nested' => [
                                        self::PERMISSION_EDIT_OTHERS => [
                                            'label' => Craft::t('live', 'Edit other people’s updates'),
                                        ],
                                        self::PERMISSION_DELETE => [
                                            'label' => Craft::t('live', 'Delete updates'),
                                        ],
                                    ],
                                ],
                                self::PERMISSION_CONTROL => [
                                    'label' => Craft::t('live', 'Start, pause and end live posts'),
                                ],
                            ],
                        ],
                        self::PERMISSION_MANAGE_TYPES => [
                            'label' => Craft::t('live', 'Manage update types'),
                        ],
                    ],
                ];
            },
        );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['live'] = 'live/posts/index';
                $event->rules['live/posts'] = 'live/posts/index';
                $event->rules['live/post/<postId:\d+>'] = 'live/posts/studio';
                $event->rules['live/types'] = 'live/update-types/index';
                $event->rules['live/types/new'] = 'live/update-types/edit';
                $event->rules['live/types/<typeId:\d+>'] = 'live/update-types/edit';
            },
        );
    }

    /**
     * Put the shipped front-end partials on the site's template path under `live/`.
     *
     * Craft looks in the site's own templates directory before any registered root, so dropping a
     * `live/_update.twig` into `templates/` overrides just that partial and leaves the rest of the
     * plugin's alone. That is the whole override story — no config, no publishing step.
     */
    private function registerTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['live'] = __DIR__ . '/templates/frontend';
            },
        );
    }

    private function registerTwig(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('live', LiveVariable::class);
            },
        );
    }

    /**
     * GraphQL (Pro).
     *
     * Registered per update type, so a schema can be granted the match commentary without also being
     * granted the newsroom's internal feed. Nothing is registered at all on Lite, which means the
     * schema changes shape on an edition switch — deliberate, and the alternative is advertising
     * queries that answer with an error.
     */
    private function registerGraphQl(): void
    {
        if (!$this->isPro()) {
            return;
        }

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_TYPES, function(RegisterGqlTypesEvent $event) {
            $event->types[] = UpdateInterface::class;
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, function(RegisterGqlQueriesEvent $event) {
            $event->queries = array_merge($event->queries, UpdateQueries::getQueries());
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS, function(RegisterGqlSchemaComponentsEvent $event) {
            $types = $this->updateTypes->getAllTypes();

            if (!$types) {
                return;
            }

            $queries = [];

            foreach ($types as $type) {
                $name = Craft::t('site', $type->name);
                $queries["liveupdatetypes.$type->uid:read"] = [
                    'label' => Craft::t('live', 'Query for “{name}” live updates', ['name' => $name]),
                ];
            }

            $event->queries[Craft::t('live', 'Live')] = $queries;
        });
    }

    /**
     * Snapshot directories outlive the posts they belong to if an entry is hard-deleted while the
     * plugin is disabled, or the snapshot path is changed. Garbage collection is where that gets
     * noticed — nothing else ever looks at a directory nobody is asking for.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->snapshots->collectGarbage();
        });
    }

    protected function afterInstall(): void
    {
        parent::afterInstall();

        if (Craft::$app->getProjectConfig()->getIsApplyingExternalChanges()) {
            return;
        }

        // A composer with no buttons on it is not much use, so an install ships with one type.
        if (!$this->updateTypes->getAllTypes()) {
            $type = new \justinholtweb\live\models\UpdateType([
                'name' => Craft::t('live', 'Update'),
                'handle' => 'update',
                'color' => 'blue',
                'icon' => 'message',
            ]);

            $this->updateTypes->saveType($type, false);
        }
    }
}
