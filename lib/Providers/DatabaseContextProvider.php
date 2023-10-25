<?php

namespace Phoenix\Database\Providers;

use Phoenix\Database\Interfaces\ModelAdapter;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Datastore\Interfaces\DataModel;

class DatabaseContextProvider
{
    public Table $table;
    public ModelAdapter $modelAdapter;
    public string $model;

    /**
     * @param Table $table
     * @param ModelAdapter $modelAdapterInstance
     * @param class-string<DataModel> $modelInstance
     */
    public function __construct(
        Table        $table,
        ModelAdapter $modelAdapterInstance,
        string       $modelInstance
    )
    {
        $this->table = $table;
        $this->modelAdapter = $modelAdapterInstance;
        $this->model = $modelInstance;
    }
}