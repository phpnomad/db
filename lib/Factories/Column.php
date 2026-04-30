<?php

namespace PHPNomad\Database\Factories;

final class Column
{
    protected string $name;
    protected string $type;
    protected ?array $typeArgs = null;
    protected array $attributes = [];

    /**
     * @var callable|null
     */
    protected $phpDefault = null;

	/**
	 * @param string   $name
	 * @param string   $type
	 * @param array|null $typeArgs
	 * @param          ...$attributes
	 */
    public function __construct(string $name, string $type, ?array $typeArgs = null, ...$attributes)
    {
        $this->name = $name;
        $this->type = $type;
        $this->typeArgs = $typeArgs;
        $this->attributes = $attributes;
    }

    /**
     * Gets the table name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the column type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Returns the length limitation on the item, or null if not set.
     *
     * @return ?array
     */
    public function getTypeArgs(): ?array
    {
        return $this->typeArgs;
    }

    /**
     * @return string[]
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Provides a PHP-side default value for this column.
     *
     * When set, the datastore layer fills in this value at create time for any
     * insert that does not already include the column. The value flows into the
     * INSERT and into the in-memory model that `create()` returns, which removes
     * the need for a post-insert read-back to capture DB-generated defaults.
     *
     * Pair this with the matching DB-side DEFAULT (e.g. `DEFAULT CURRENT_TIMESTAMP`)
     * for belt-and-suspenders coverage when rows are inserted outside the framework.
     *
     * The callable receives no arguments and should return a value already in the
     * shape the column expects (e.g. a MySQL-format datetime string for a TIMESTAMP).
     *
     * @param callable():mixed $default
     * @return self
     */
    public function withPhpDefault(callable $default): self
    {
        $this->phpDefault = $default;

        return $this;
    }

    /**
     * Returns the PHP-side default callable, or null if none is set.
     *
     * @return callable|null
     */
    public function getPhpDefault(): ?callable
    {
        return $this->phpDefault;
    }
}
