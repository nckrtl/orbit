<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use SortDirection;

/**
 * Subtask queries still say task_group_id. On the merged table that column is parent_id.
 */
final class TaskColumnQueryBuilder extends Builder
{
    public function select($columns = ['*'])
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $columns = array_map(fn (mixed $column): mixed => is_string($column) ? $this->rewriteColumn($column) : $column, $columns);

        return parent::select($columns);
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_string($column)) {
            $column = $this->rewriteColumn($column);
        }

        $count = func_num_args();

        if ($count === 1) {
            return parent::where($column);
        }

        if ($count === 2) {
            return parent::where($column, $operator);
        }

        if ($count === 3) {
            return parent::where($column, $operator, $value);
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if ($column instanceof Expression) {
            return parent::whereIn($column, $values, $boolean, $not);
        }

        return parent::whereIn($this->rewriteColumn($column), $values, $boolean, $not);
    }

    public function whereNull($columns, $boolean = 'and', $not = false)
    {
        if (is_array($columns)) {
            return parent::whereNull(array_map($this->rewriteColumn(...), $columns), $boolean, $not);
        }

        if ($columns instanceof Expression) {
            return parent::whereNull($columns, $boolean, $not);
        }

        return parent::whereNull($this->rewriteColumn($columns), $boolean, $not);
    }

    public function orderBy($column, $direction = SortDirection::Ascending)
    {
        if (! is_string($column)) {
            return parent::orderBy($column, $direction);
        }

        return parent::orderBy($this->rewriteColumn($column), $direction);
    }

    public function get($columns = ['*'])
    {
        if (is_array($this->columns)) {
            $this->columns = $this->rewriteList($this->columns);
        } elseif (is_array($columns)) {
            $columns = $this->rewriteList($columns);
        } elseif (is_string($columns)) {
            $columns = $this->rewriteColumn($columns);
        }

        return parent::get($columns);
    }

    public function pluck($column, $key = null)
    {
        if (is_string($column)) {
            $column = $this->rewriteColumn($column);
        }

        if (is_string($key)) {
            $key = $this->rewriteColumn($key);
        }

        return parent::pluck($column, $key);
    }

    /**
     * @param  array<int|string, Expression|string>  $columns
     * @return array<int|string, Expression|string>
     */
    private function rewriteList(array $columns): array
    {
        $rewritten = [];

        foreach ($columns as $alias => $column) {
            $rewritten[$alias] = is_string($column) ? $this->rewriteColumn($column) : $column;
        }

        return $rewritten;
    }

    private function rewriteColumn(string $column): string
    {
        $connection = $this->connection;

        if (! $connection instanceof Connection || ! TaskSchema::merged($connection)) {
            return $column;
        }

        return match ($column) {
            'task_group_id' => 'parent_id',
            'tasks.task_group_id' => 'tasks.parent_id',
            default => $column,
        };
    }
}
