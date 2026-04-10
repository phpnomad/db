# phpnomad/db

[![Latest Version](https://img.shields.io/packagist/v/phpnomad/db.svg)](https://packagist.org/packages/phpnomad/db)
[![Total Downloads](https://img.shields.io/packagist/dt/phpnomad/db.svg)](https://packagist.org/packages/phpnomad/db)
[![PHP Version](https://img.shields.io/packagist/php-v/phpnomad/db.svg)](https://packagist.org/packages/phpnomad/db)
[![License](https://img.shields.io/packagist/l/phpnomad/db.svg)](https://packagist.org/packages/phpnomad/db)

`phpnomad/db` implements the datastore pattern for SQL databases. It gives you table schemas, query building, automatic caching, and event broadcasting on top of the storage-agnostic abstractions in `phpnomad/datastore`, so your handlers stay free of raw SQL and your domain logic stays portable.

You get full CRUD operations, condition-array querying, cache lookups on reads, cache invalidation on writes, and `RecordCreated`, `RecordUpdated`, and `RecordDeleted` events dispatched from every mutation. The package powers the data layer in [Siren](https://sirenaffiliates.com) and has been in production for years.

## Installation

```bash
composer require phpnomad/db
```

This package is the abstraction layer. To actually execute queries you also need a concrete integration like `phpnomad/mysql-db-integration`, which provides the `QueryStrategy` and table-management strategies that `phpnomad/db` delegates to.

## Quick Start

Define a table schema by extending `Table`. Columns and indices come from value objects and factories, and a table version string lets migrations detect schema changes.

```php
<?php

use PHPNomad\Database\Abstracts\Table;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Columns\DateCreatedFactory;
use PHPNomad\Database\Factories\Columns\DateModifiedFactory;
use PHPNomad\Database\Factories\Columns\PrimaryKeyFactory;
use PHPNomad\Database\Factories\Index;

class PostsTable extends Table
{
    public function getUnprefixedName(): string
    {
        return 'posts';
    }

    public function getSingularUnprefixedName(): string
    {
        return 'post';
    }

    public function getAlias(): string
    {
        return 'p';
    }

    public function getTableVersion(): string
    {
        return '1';
    }

    public function getColumns(): array
    {
        return [
            (new PrimaryKeyFactory())->toColumn(),
            new Column('title', 'VARCHAR', [255], 'NOT NULL'),
            new Column('content', 'TEXT', null, 'NOT NULL'),
            new Column('status', 'VARCHAR', [20], "NOT NULL DEFAULT 'draft'"),
            (new DateCreatedFactory())->toColumn(),
            (new DateModifiedFactory())->toColumn(),
        ];
    }

    public function getIndices(): array
    {
        return [
            new Index(['status'], 'idx_posts_status'),
        ];
    }
}
```

Create a handler by extending `IdentifiableDatabaseDatastoreHandler` and pulling in `WithDatastoreHandlerMethods`. The base class implements `find`, `findMultiple`, `update`, and `delete` against the `id` column, and the trait supplies the rest of the CRUD surface along with cache and event wiring.

```php
<?php

use PHPNomad\Database\Abstracts\IdentifiableDatabaseDatastoreHandler;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Traits\WithDatastoreHandlerMethods;

class PostDatabaseDatastoreHandler extends IdentifiableDatabaseDatastoreHandler
{
    use WithDatastoreHandlerMethods;

    public function __construct(
        DatabaseServiceProvider $serviceProvider,
        PostsTable              $table,
        PostAdapter             $adapter,
        TableSchemaService      $tableSchemaService
    ) {
        $this->serviceProvider    = $serviceProvider;
        $this->table              = $table;
        $this->modelAdapter       = $adapter;
        $this->tableSchemaService = $tableSchemaService;
        $this->model              = Post::class;
    }
}
```

Once the handler is wired into your container, reads hit the cache first and writes invalidate it automatically. Complex reads use condition arrays instead of raw SQL.

```php
$published = $postHandler->where([
    [
        'type' => 'AND',
        'clauses' => [
            ['column' => 'status', 'operator' => '=', 'value' => 'published'],
            ['column' => 'views', 'operator' => '>', 'value' => 1000],
        ],
    ],
], limit: 10);
```

The `QueryBuilder` turns that array into parameterized SQL and runs it through whichever `QueryStrategy` your integration package provides.

## Key Concepts

- Extend `Table` to define a schema with columns, indices, and a version string for migrations
- Extend `IdentifiableDatabaseDatastoreHandler` as the base for any handler keyed by a single `id` column
- Use the `WithDatastoreHandlerMethods` trait for CRUD, cache reads, cache invalidation, and event dispatch
- Build reads and writes through `QueryBuilder` and `ClauseBuilder` with condition arrays instead of raw SQL
- Inject `DatabaseServiceProvider` into every handler to access `QueryBuilder`, `QueryStrategy`, `ClauseBuilder`, `CacheableService`, `EventStrategy`, and `LoggerStrategy`
- Use column factories like `PrimaryKeyFactory`, `DateCreatedFactory`, `DateModifiedFactory`, and `ForeignKeyFactory` for common column patterns
- Model many-to-many relationships with `JunctionTable`, which handles compound primary keys and foreign key constraints

## Documentation

Full documentation lives at [phpnomad.com](https://phpnomad.com), including detailed guides on table schema definition, database handlers, query building, caching and event broadcasting, junction tables, and the column and index factories.

## License

MIT License. See [LICENSE.txt](LICENSE.txt).
