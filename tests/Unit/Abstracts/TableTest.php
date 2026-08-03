<?php

namespace PHPNomad\Database\Tests\Unit\Abstracts;

use PHPNomad\Database\Abstracts\Table;
use PHPNomad\Database\Interfaces\HasCharsetProvider;
use PHPNomad\Database\Interfaces\HasCollateProvider;
use PHPNomad\Database\Interfaces\HasGlobalDatabasePrefix;
use PHPNomad\Database\Interfaces\HasLocalDatabasePrefix;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Tests\TestCase;

class TableTest extends TestCase
{
    public function testGetNameIncludesBothPrefixes(): void
    {
        $this->assertSame('glob_loc_name', $this->makeTable('glob', 'loc')->getName());
    }

    public function testGetNameOmitsAnEmptyGlobalPrefix(): void
    {
        $this->assertSame('loc_name', $this->makeTable('', 'loc')->getName());
    }

    public function testGetNameOmitsAnEmptyLocalPrefix(): void
    {
        $this->assertSame('glob_name', $this->makeTable('glob', '')->getName());
    }

    public function testGetNameOmitsBothEmptyPrefixes(): void
    {
        $this->assertSame('name', $this->makeTable('', '')->getName());
    }

    private function makeTable(string $globalPrefix, string $localPrefix): Table
    {
        $globalPrefixProvider = $this->createMock(HasGlobalDatabasePrefix::class);
        $globalPrefixProvider->method('getGlobalDatabasePrefix')->willReturn($globalPrefix);

        $localPrefixProvider = $this->createMock(HasLocalDatabasePrefix::class);
        $localPrefixProvider->method('getLocalDatabasePrefix')->willReturn($localPrefix);

        return new class(
            $localPrefixProvider,
            $globalPrefixProvider,
            $this->createMock(HasCharsetProvider::class),
            $this->createMock(HasCollateProvider::class),
            $this->createMock(TableSchemaService::class)
        ) extends Table {
            public function getUnprefixedName(): string
            {
                return 'name';
            }

            public function getAlias(): string
            {
                return 'name';
            }

            public function getTableVersion(): string
            {
                return '1';
            }

            public function getColumns(): array
            {
                return [];
            }

            public function getIndices(): array
            {
                return [];
            }

            public function getSingularUnprefixedName(): string
            {
                return 'name';
            }
        };
    }
}
