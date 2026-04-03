<?php

declare(strict_types=1);

namespace Myxa\Database\Model;

use Myxa\Database\DatabaseManager;

/**
 * Base relation query that supports both lazy and eager loading.
 *
 * @template TModel of Model
 * @extends ModelQuery<TModel>
 */
abstract class Relation extends ModelQuery
{
    /**
     * @param class-string<TModel> $related
     */
    public function __construct(
        protected readonly Model $parent,
        protected readonly string $related,
        protected readonly DatabaseManager $relationManager,
        protected readonly ?string $relationConnection,
    ) {
        parent::__construct($related, $relationManager, $relationConnection);
    }

    /**
     * Return the related model class for this relation.
     *
     * @return class-string<TModel>
     */
    public function relatedModelClass(): string
    {
        return $this->related;
    }

    /**
     * Eager load the relation onto a set of parent models and return the loaded children.
     *
     * @param list<Model> $models
     * @return list<Model>
     */
    abstract public function eagerLoad(array $models, string $relation): array;

    /**
     * Build a fresh query for eager loading without the lazy parent constraint.
     *
     * @return ModelQuery<TModel>
     */
    protected function newRelatedQuery(): ModelQuery
    {
        return new ModelQuery($this->related, $this->relationManager, $this->relationConnection);
    }
}
