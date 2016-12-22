<?php

namespace Jenky\RESTfulEloquent;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait RESTful
{
    /**
     * Supported fixes and their where condition query.
     *
     * @var array
     */
    public $supportedFixes = [
        'lt' => '<',
        'gt' => '>',
        'lte' => '<=',
        'gte' => '>=',
        'lk' => 'LIKE',
        'not-lk' => 'NOT LIKE',
        'in' => 'IN',
        'not-in' => 'NOT IN',
        'not' => '!=',
    ];

    /**
     * Fitler data by provided query string conditions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  string[] $cols
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFilterBy($query, ...$cols)
    {
        $cols = Arr::flatten($cols);

        foreach (array_filter(request()->all()) as $key => $value) {
            extract($this->parseFilterParameter($key, $value));

            if ($this->isWhitelistedParameter($column, $cols)) {
                $query = $this->buildWhereClause($query, $column, $operator, $value);
            }
        }

        return $query;
    }

    /**
     * Parse querystring and it's value to where clause condition.
     *
     * @param  string $key
     * @param  mixed $value
     * @return array
     */
    protected function parseFilterParameter($key, $value)
    {
        $prefixes = implode('|', $this->supportedFixes);
        $suffixes = implode('|', array_keys($this->supportedFixes));
        $matches = [];

        // Matches every parameter with an optional prefix and/or postfix
        // e.g. not-title-lk, title-lk, not-title, title
        $regex = '/^(?:('.$prefixes.')-)?(.*?)(?:-('.$suffixes.')|$)/';
        preg_match($regex, $key, $matches);

        if (! isset($matches[3])) {
            if (Str::lower(trim($value)) == 'null') {
                $operator = 'NULL';
            } else {
                $operator = '=';
            }
        } else {
            if (Str::lower(trim($value)) == 'null') {
                $operator = 'NOT NULL';
            } else {
                $operator = $this->supportedFixes[$matches[3]];
            }
        }

        $column = isset($matches[2]) ? $matches[2] : null;

        return compact('operator', 'column', 'matches');
    }

    /**
     * Append where clause to query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  string $column
     * @param  string $operator
     * @param  mixed $value
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildWhereClause($query, $column, $operator, $value)
    {
        if ($operator == 'IN') {
            $query = $query->whereIn($column, explode(',', $value));
        } elseif ($operator == 'NOT IN') {
            $query = $query->whereNotIn($column, explode(',', $value));
        } else {
            $values = explode('|', $value);
            if (count($values) > 1) {
                $query = $query->where(function ($q) use ($column, $operator, $values) {
                    foreach ($values as $value) {
                        $value = in_array($operator, ['LIKE', 'NOT LIKE'])
                            ? preg_replace('/(^\*|\*$)/', '%', $value)
                            : $value;

                        // Link the filters with AND of there is a "not" and with OR if there's none
                        if (in_array($operator, ['!=', 'NOT LIKE'])) {
                            $q->where($column, $operator, $value);
                        } else {
                            $q->orWhere($column, $operator, $value);
                        }
                    }
                });
            } else {
                $value = in_array($operator, ['LIKE', 'NOT LIKE'])
                    ? preg_replace('/(^\*|\*$)/', '%', $values[0])
                    : $values[0];

                if (in_array($operator, ['NULL', 'NOT NULL'])) {
                    $query = $query->whereNull($column, 'and', $operator == 'NOT NULL');
                } else {
                    $query = $query->where($column, $operator, $value);
                }
            }
        }

        return $query;
    }

    /**
     * Sort by data from "sort by" query string parameter.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  string[] $cols
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSortBy($query, ...$cols)
    {
        $data = request($this->getSortByParameterKey());

        return $this->scopeSortWith($query, explode(',', $data), $cols);
    }

    /**
     * Sort by provided conditions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  string|array $conditions
     * @param  array $cols
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSortWith($query, $conditions, array $cols = [])
    {
        $cols = Arr::flatten($cols);

        foreach ((array) $conditions as $sort) {
            // Check if ascending or descending(-) sort
            if (preg_match('/^-.+/', $sort)) {
                $direction = 'desc';
            } else {
                $direction = 'asc';
            }

            $sort = preg_replace('/^-/', '', $sort);

            if ($this->isWhitelistedParameter($sort, $cols)) {
                $query->orderBy($sort, $direction);
            }
        }

        return $query;
    }

    /**
     * Check if parameter can be sorted, filtered, etc...
     *
     * @param  string $param
     * @param  array $allowed
     * @return bool
     */
    public function isWhitelistedParameter($param, array $allowed)
    {
        if (in_array('*', $allowed)) {
            return true;
        }

        return in_array($param, $allowed);
    }

    /**
     * Get the name/key of "sort by" query string parameter from request.
     *
     * @return string
     */
    public function getSortByParameterKey()
    {
        return defined('static::SORT_BY') ? static::SORT_BY : 'sort';
    }
}
