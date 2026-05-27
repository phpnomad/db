<?php

namespace PHPNomad\Database\Tests\Unit\Factories\Columns;

use DateTimeImmutable;
use PHPNomad\Chrono\Interfaces\ClockStrategy;
use PHPNomad\Database\Factories\Columns\DateCreatedFactory;
use PHPNomad\Database\Tests\TestCase;

class DateCreatedFactoryTest extends TestCase
{
    private function makeClock(string $instant = '2026-05-27 12:34:56'): ClockStrategy
    {
        return new class ($instant) implements ClockStrategy {
            public function __construct(private string $instant)
            {
            }
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->instant);
            }
        };
    }

    public function testProducesDateCreatedColumnWithDbDefault(): void
    {
        $column = (new DateCreatedFactory($this->makeClock()))->toColumn();

        $this->assertSame('dateCreated', $column->getName());
        $this->assertSame('TIMESTAMP', $column->getType());
        $this->assertSame(['NOT NULL DEFAULT CURRENT_TIMESTAMP'], $column->getAttributes());
    }

    public function testProvidesPhpDefaultThatReturnsMysqlFormatTimestamp(): void
    {
        $column = (new DateCreatedFactory($this->makeClock()))->toColumn();
        $default = $column->getPhpDefault();

        $this->assertIsCallable($default);

        $value = $default();

        $this->assertIsString($value);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value);
    }

    public function testPhpDefaultReadsFromInjectedClock(): void
    {
        $column = (new DateCreatedFactory($this->makeClock('2026-05-27 12:34:56')))->toColumn();
        $default = $column->getPhpDefault();

        $this->assertSame('2026-05-27 12:34:56', $default());
    }
}
