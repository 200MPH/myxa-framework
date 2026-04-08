<?php

declare(strict_types=1);

namespace Myxa\Database\Model;

use Myxa\Database\DatabaseManager;

/**
 * Has-many relation query and eager loader.
 *
 * @template TModel of Model
 * @extends Relation<TModel>
 */
final class HasManyRelation extends Relation
{
    /**
     * @param class-string<TModel> $related
     */
    public function __construct(
        Model $parent,
        string $related,
        DatabaseManager $manager,
        ?string $connection,
        private readonly string $foreignKey,
        private readonly string $localKey,
    ) {
        parent::__construct($parent, $related, $manager, $connection);

        $this->where($this->foreignKey, '=', $this->parent->getAttribute($this->localKey));
    }

    public function eagerLoad(array $models, string $relation): array
    {
        $keys = [];

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);
            if ($key !== null) {
                $keys[(string) $key] = $key;
            }
        }

        if ($keys === []) {
            foreach ($models as $model) {
                $model->setRelation($relation, []);
            }

            return [];
        }

        $relatedModels = $this->newRelatedQuery()
            ->whereIn($this->foreignKey, array_values($keys))
            ->get();

        $grouped = [];

        foreach ($relatedModels as $relatedModel) {
            $grouped[(string) $relatedModel->getAttribute($this->foreignKey)][] = $relatedModel;
        }

        foreach ($models as $model) {
            $model->setRelation(
                $relation,
                $grouped[(string) $model->getAttribute($this->localKey)] ?? [],
            );
        }

        return $relatedModels;
    }
}
