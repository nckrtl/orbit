<?php

declare(strict_types=1);

namespace App\Models\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * MorphMany that writes the mapped alias and still matches pre-upgrade class names.
 *
 * @extends MorphMany<Model, Model>
 */
final class DualSafeMorphMany extends MorphMany
{
    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $morphTypes
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $type,
        string $id,
        string $localKey,
        private readonly array $morphTypes,
    ) {
        parent::__construct($query, $parent, $type, $id, $localKey);
    }

    public function addConstraints(): void
    {
        if (self::$constraints) {
            $query = $this->getRelationQuery();
            $query->where($this->foreignKey, '=', $this->getParentKey());
            $query->whereNotNull($this->foreignKey);
            $query->whereIn($this->morphType, $this->morphTypes);
        }
    }

    /** @param  list<Model>  $models */
    public function addEagerConstraints(array $models): void
    {
        $whereIn = $this->whereInMethod($this->parent, $this->localKey);
        $this->whereInEager(
            $whereIn,
            $this->foreignKey,
            $this->getKeys($models, $this->localKey),
            $this->getRelationQuery(),
        );
        $this->getRelationQuery()->whereIn($this->morphType, $this->morphTypes);
    }

    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $builder = $query->getQuery()->from == $parentQuery->getQuery()->from
            ? $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns)
            : $query->select($columns)->whereColumn(
                $this->getQualifiedParentKeyName(),
                '=',
                $this->getExistenceCompareKey(),
            );

        return $builder->whereIn($query->qualifyColumn($this->getMorphType()), $this->morphTypes);
    }
}
