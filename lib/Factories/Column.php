<?php

namespace Phoenix\Database\Factories;

final class Column
{
    protected string $name;
    protected string $type;
    protected ?int $length = null;
    protected array $attributes = [];

	/**
	 * @param string   $name
	 * @param string   $type
	 * @param int|null $length
	 * @param          ...$attributes
	 */
    public function __construct(string $name, string $type, ?int $length = null, ...$attributes)
    {
        $this->name = $name;
        $this->type = $type;
        $this->length = $length;
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
     * @return ?int
     */
    public function getLength(): ?int
    {
        return $this->length;
    }

    /**
     * @return string[]
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
}