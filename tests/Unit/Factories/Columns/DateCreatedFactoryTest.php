<?php

namespace PHPNomad\Database\Tests\Unit\Factories\Columns;

use PHPNomad\Database\Factories\Columns\DateCreatedFactory;
use PHPNomad\Database\Tests\TestCase;

class DateCreatedFactoryTest extends TestCase
{
    public function testProducesDateCreatedColumnWithDbDefault(): void
    {
        $column = (new DateCreatedFactory())->toColumn();

        $this->assertSame('dateCreated', $column->getName());
        $this->assertSame('TIMESTAMP', $column->getType());
        $this->assertSame(['NOT NULL DEFAULT CURRENT_TIMESTAMP'], $column->getAttributes());
    }

    public function testProvidesPhpDefaultThatReturnsMysqlFormatTimestamp(): void
    {
        $column = (new DateCreatedFactory())->toColumn();
        $default = $column->getPhpDefault();

        $this->assertIsCallable($default);

        $value = $default();

        $this->assertIsString($value);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value);
    }
}
