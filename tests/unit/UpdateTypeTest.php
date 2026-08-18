<?php

declare(strict_types=1);

namespace justinholtweb\live\tests\unit;

use craft\enums\Color;
use justinholtweb\live\models\UpdateType;
use PHPUnit\Framework\TestCase;

/**
 * Update types.
 *
 * `getConfig()` is the shape that goes into project config, which means it is the shape that has to
 * survive the trip from a developer's laptop to production. A key that quietly stops being written
 * is a setting that quietly stops deploying.
 */
final class UpdateTypeTest extends TestCase
{
    public function testConfigCarriesEverySetting(): void
    {
        $type = new UpdateType([
            'name' => 'Goal',
            'handle' => 'goal',
            'icon' => 'futbol',
            'color' => 'green',
            'description' => 'Someone has scored.',
            'hasTitleField' => true,
            'titleFormat' => '{seq}',
            'showsInComposer' => false,
            'sortOrder' => 3,
        ]);

        self::assertSame([
            'name' => 'Goal',
            'handle' => 'goal',
            'icon' => 'futbol',
            'color' => 'green',
            'description' => 'Someone has scored.',
            'hasTitleField' => true,
            'titleFormat' => '{seq}',
            'showsInComposer' => false,
            'sortOrder' => 3,
        ], $type->getConfig());
    }

    public function testEmptyStringsBecomeNullInConfig(): void
    {
        // Project config diffs are read by people. `"icon": ""` and `"icon": null` mean the same
        // thing and one of them is noise in every future diff.
        $type = new UpdateType([
            'name' => 'Update',
            'handle' => 'update',
            'icon' => '',
            'color' => '',
            'description' => '',
            'titleFormat' => '',
        ]);

        $config = $type->getConfig();

        self::assertNull($config['icon']);
        self::assertNull($config['color']);
        self::assertNull($config['description']);
        self::assertNull($config['titleFormat']);
    }

    public function testSortOrderDefaultsToZeroRatherThanNull(): void
    {
        $config = (new UpdateType(['name' => 'Update', 'handle' => 'update']))->getConfig();

        self::assertSame(0, $config['sortOrder']);
    }

    public function testResolvesItsColour(): void
    {
        self::assertSame(Color::Green, (new UpdateType(['color' => 'green']))->getColorEnum());
    }

    public function testAnUnknownColourResolvesToNothingRatherThanBlowingUp(): void
    {
        // The colour comes out of project config, which a human may have edited.
        self::assertNull((new UpdateType(['color' => 'plaid']))->getColorEnum());
        self::assertNull((new UpdateType())->getColorEnum());
    }

    public function testStringifiesToItsHandle(): void
    {
        self::assertSame('goal', (string)new UpdateType(['handle' => 'goal']));
    }

    public function testShowsInComposerByDefault(): void
    {
        // A type nobody can pick is a type nobody knows they have.
        self::assertTrue((new UpdateType())->showsInComposer);
        self::assertFalse((new UpdateType())->hasTitleField);
    }
}
