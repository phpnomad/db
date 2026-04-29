<?php

namespace PHPNomad\Database\Tests\Unit\Factories\Columns;

use PHPNomad\Database\Factories\Columns\DateModifiedFactory;
use PHPNomad\Database\Tests\TestCase;

class DateModifiedFactoryTest extends TestCase
{
    public function testProducesDateModifiedColumnWithDbDefault(): void
    {
        $column = (new DateModifiedFactory())->toColumn();

        $this->assertSame('dateModified', $column->getName());
        $this->assertSame('TIMESTAMP', $column->getType());
        $this->assertSame(['NOT NULL DEFAULT CURRENT_TIMESTAMP'], $column->getAttributes());
    }

    public function testProvidesPhpDefaultThatReturnsMysqlFormatTimestamp(): void
    {
        $column = (new DateModifiedFactory())->toColumn();
        $default = $column->getPhpDefault();

        $this->assertIsCallable($default);

        $value = $default();

        $this->assertIsString($value);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value);
    }
}
