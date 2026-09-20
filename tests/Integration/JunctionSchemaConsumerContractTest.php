<?php

namespace PHPNomad\Database\Tests\Integration;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Tests\Integration\Fixtures\JunctionSchemaConsumerFixture;
use PHPNomad\Database\Tests\TestCase;

final class JunctionSchemaConsumerContractTest extends TestCase
{
    public function testConsumerOverridesProduceCompleteJunctionMetadata(): void
    {
        $consumer = new JunctionSchemaConsumerFixture();
        $junction = $consumer->junction();
        $schema = $consumer->schema();

        self::assertSame('global_local_programsDistributors', $junction->getName());
        self::assertSame(['customProgramExternalKey', 'customDistributorExternalKey'], $junction->getFieldsForIdentity());
        self::assertSame([
            ['customProgramExternalKey', 'BIGINT'], ['customDistributorExternalKey', 'BIGINT'],
        ], array_map(static fn (Column $column): array => [$column->getName(), $column->getType()], $junction->getColumns()));
        self::assertSame([
            ['PRIMARY KEY', ['customDistributorExternalKey', 'customProgramExternalKey'], []],
            ['FOREIGN KEY', ['customProgramExternalKey'], ['REFERENCES global_local_programs(externalKey)']],
            ['FOREIGN KEY', ['customDistributorExternalKey'], ['REFERENCES global_local_distributors(externalKey)']],
        ], array_map(static fn (Index $index): array => [
            $index->getType(), $index->getColumns(), $index->getAttributes(),
        ], $junction->getIndices()));

        $primary = $schema->getPrimaryColumnsForTable($junction);
        self::assertSame(['customProgramExternalKey', 'customDistributorExternalKey'], array_map(
            static fn (Column $column): string => $column->getName(), $primary
        ));
        self::assertSame($primary, $schema->getPrimaryColumnsForTable($junction));
        self::assertSame(1, $consumer->cacheEntryCount());
    }
}
