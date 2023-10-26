<?php

namespace Phoenix\Database\Factories;

use InvalidArgumentException;
use Phoenix\Datastore\Exceptions\DuplicateEntryException;
use Phoenix\Datastore\Interfaces\JunctionContextProvider;
use Phoenix\Datastore\Interfaces\JunctionHandler as JunctionHandlerInterface;
use Phoenix\Utils\Helpers\Arr;

class JunctionHandler implements JunctionHandlerInterface
{

    /**
     * Provider for the "left" datastore. Generally accessed using getContextForResource() or getOppositeContext()
     * to identify the correct one.
     *
     * @var JunctionContextProvider
     */
    public JunctionContextProvider $leftProvider;

    /**
     * Provider for the "right" datastore. Generally accessed using getContextForResource() or getOppositeContext()
     * to identify the correct one.
     *
     * @var JunctionContextProvider
     */
    public JunctionContextProvider $rightProvider;

    /**
     * Provider for the datastore that binds the two other datastores together.
     *
     * @var JunctionContextProvider
     */
    public JunctionContextProvider $middleProvider;

    public function __construct(
        JunctionContextProvider $leftProvider,
        JunctionContextProvider $rightProvider,
        JunctionContextProvider $middleProvider
    )
    {
        $this->leftProvider = $leftProvider;
        $this->rightProvider = $rightProvider;
        $this->middleProvider = $middleProvider;
    }

    /**
     * @param string $resource
     * @return JunctionContextProvider
     */
    protected function getContextforResource(string $resource): JunctionContextProvider
    {
        if ($this->leftProvider->getResource() === $resource) {
            return $this->leftProvider;
        }

        if ($this->rightProvider->getResource() === $resource) {
            return $this->rightProvider;
        }

        throw new InvalidArgumentException('The provided resource is invalid for this junction. Valid options are ' . $this->leftProvider->getResource() . ', ' . $this->rightProvider->getResource());
    }

    protected function getOppositeContext(JunctionContextProvider $junctionContextProvider): JunctionContextProvider
    {
        return $junctionContextProvider === $this->leftProvider ? $this->rightProvider : $this->leftProvider;
    }

    /** @inheritDoc */
    public function getIdsFromResource(string $resource, int $id, int $limit, int $offset): array
    {
        $context = $this->getContextForResource($resource);
        $opposite = $this->getOppositeContext($context);

        return $context->getDatastore()->findIds([$opposite->getJunctionFieldName(), '=', $id], $limit, $offset);
    }

    /** @inheritDoc */
    public function bind(string $resource, int $id, int $bindingId): void
    {
        $context = $this->getContextForResource($resource);
        $binding = $this->getOppositeContext($context);

        $exists = $this->middleProvider->getDatastore()->where([
            [$binding->getJunctionFieldName(), '=', $bindingId],
            [$context->getJunctionFieldName(), '=', $id]
        ], 1);

        if ($exists) {
            throw new DuplicateEntryException('The specified binding already exists');
        }

        $this->middleProvider->getDatastore()->create([
            $binding->getJunctionFieldName() => $bindingId,
            $context->getJunctionFieldName() => $id
        ]);
    }

    /** @inheritDoc */
    public function unbind(string $resource, int $id, int $bindingId): void
    {
        $context = $this->getContextForResource($resource);
        $binding = $this->getOppositeContext($context);

        $this->middleProvider->getDatastore()->deleteWhere([
            [$binding->getJunctionFieldName(), '=', $bindingId],
            [$context->getJunctionFieldName(), '=', $id]
        ]);
    }

    /** @inheritDoc */
    public function getModelsFromResource(string $resource, int $id, int $limit, int $offset): array
    {
        $context = $this->getContextForResource($resource);
        $ids = $this->getIdsFromResource($resource, $id, $limit, $offset);

        return $context->getDatastore()->where([['id', 'IN', $ids]]);
    }
}