<?php

namespace Phoenix\Database\Factories;

final class Index
{
    protected string $name;
    protected array $columns;
    protected bool $isUnique;
    protected bool $isPrimary;
    protected ?string $type = null;
    protected array $attributes = [];

    public function __construct(
        string $name,
        array $columns,
        bool $isUnique = false,
        bool $isPrimary = false,
        ?string $type = null,
        ...$attributes
    ) {
        $this->name = $name;
        $this->columns = $columns;
        $this->isUnique = $isUnique;
        $this->isPrimary = $isPrimary;
        $this->type = $type;
        $this->attributes = $attributes;
    }

    /**
     * Gets the index name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the columns that are part of the index.
     *
     * @return array|string[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Determines if the index is unique.
     *
     * @return bool
     */
    public function isUnique(): bool
    {
        return $this->isUnique;
    }

    /**
     * Determines if the index is a primary key.
     *
     * @return bool
     */
    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    /**
     * Gets the type of the index.
     *
     * @return ?string
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Gets any additional attributes or options for the index.
     *
     * @return array
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
}
