<?php

declare(strict_types=1);

namespace Myxa\Database\Model;

use Myxa\Database\DatabaseManager;

/**
 * Belongs-to relation query and eager loader.
 *
 * @template TModel of Model
 * @extends Relation<TModel>
 */
final class BelongsToRelation extends Relation
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
        private readonly string $ownerKey,
    ) {
        parent::__construct($parent, $related, $manager, $connection);

        $this->where($this->ownerKey, '=', $this->parent->getAttribute($this->foreignKey))
            ->limit(1);
    }

    public function eagerLoad(array $models, string $relation): array
    {
        $keys = [];

        foreach ($models as $model) {
            $key = $model->getAttribute($this->foreignKey);
            if ($key !== null) {
                $keys[(string) $key] = $key;
            }
        }

        if ($keys === []) {
            foreach ($models as $model) {
                $model->setRelation($relation, null);
            }

            return [];
        }

        $relatedModels = $this->newRelatedQuery()
            ->whereIn($this->ownerKey, array_values($keys))
            ->get();

        $indexed = [];

        foreach ($relatedModels as $relatedModel) {
            $indexed[(string) $relatedModel->getAttribute($this->ownerKey)] = $relatedModel;
        }

        foreach ($models as $model) {
            $model->setRelation(
                $relation,
                $indexed[(string) $model->getAttribute($this->foreignKey)] ?? null,
            );
        }

        return $relatedModels;
    }
}
