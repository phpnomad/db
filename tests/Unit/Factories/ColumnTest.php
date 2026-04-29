<?php

namespace PHPNomad\Database\Tests\Unit\Factories;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Tests\TestCase;

class ColumnTest extends TestCase
{
    public function testGetPhpDefaultIsNullByDefault(): void
    {
        $column = new Column('name', 'VARCHAR', [255]);

        $this->assertNull($column->getPhpDefault());
    }

    public function testWithPhpDefaultStoresAndReturnsCallable(): void
    {
        $column = (new Column('createdAt', 'TIMESTAMP'))
            ->withPhpDefault(static fn () => 'now');

        $default = $column->getPhpDefault();

        $this->assertIsCallable($default);
        $this->assertSame('now', $default());
    }

    public function testWithPhpDefaultIsFluent(): void
    {
        $column = new Column('createdAt', 'TIMESTAMP');

        $returned = $column->withPhpDefault(static fn () => 'now');

        $this->assertSame($column, $returned);
    }

    public function testWithPhpDefaultDoesNotAffectOtherFields(): void
    {
        $column = (new Column('createdAt', 'TIMESTAMP', null, 'NOT NULL DEFAULT CURRENT_TIMESTAMP'))
            ->withPhpDefault(static fn () => 'now');

        $this->assertSame('createdAt', $column->getName());
        $this->assertSame('TIMESTAMP', $column->getType());
        $this->assertNull($column->getTypeArgs());
        $this->assertSame(['NOT NULL DEFAULT CURRENT_TIMESTAMP'], $column->getAttributes());
    }
}
