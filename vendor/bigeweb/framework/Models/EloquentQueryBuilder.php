<?php

namespace illuminate\Support\Models;

use http\Exception\InvalidArgumentException;
use illuminate\Support\Database\DestroyQueryBuilder;
use illuminate\Support\Database\Paginator;
use illuminate\Support\Exceptions\EmptyDataException;

class EloquentQueryBuilder extends Paginator
{

    protected $queryLimit;
    protected $whereClause = [];
    protected $orWhereClause = [];
    protected $orderbyClause;
    protected $groupbyClause;
    private $baseModel;
    protected $getConnection;
    private $query;
    protected $table;
    private $deletedAtTimestamp;
    private ?string $columnName = "deleted_at";
    private bool $withTrashed = false;
    private bool $onlyTrashed = false;
    protected $jointClause = [];
    protected $jointTableClause = [];
    protected $selectClause = [];
    protected $isCount = false;
    public $total_records;
    public $current_page;
    public $total_pages;
    public $row_per_page;

    public function __construct($model)
    {
        $this->baseModel = $model;
        $this->getConnection = $model->getConnection();
        $this->table = $model->table();
        $this->deletedAtTimestamp = date('Y-m-d H:i:s');
    }


    /**
     * @param int $id
     * @return object|mixed|null
     *
     */
    public function find(int $id): ?object
    {
        return $this->where('id', '=', $id)->first();
    }


    public function findOrFail(int $id): ?object
    {
        $result = $this->find($id);
        if (!$result) {
            EmptyDataException::errorMessage();
        }
        return $result;
    }

    /**
     * @param $column
     * @param $operator
     * @param $value
     * @return $this
     *
     */
    public function where(array|string $column, $operator = null, $value = null): self
    {
        if (is_array($column)) {
            foreach ($column as $key => $value) {
                $this->whereClause[] = "$key = '$value'";
            }
        } else if (!empty($column) && !empty($operator) && !empty($value)) {
            $this->whereClause[] = "$column $operator '$value'";
        }
        return $this;
    }


    /**
     * @return array
     * Fetch all data from database
     */
    public function get(): array
    {
        $select = "*";
        if ($this->selectClause) {
            $select = implode(", ", $this->selectClause);
        } else {
            $select = "{$this->table}.*";
        }

        if (!empty($this->jointTableClause)) {
            $select .= ', ' . implode(', ', $this->jointTableClause);
        }


        $query = $this->buildBaseSelect($select);

        if (!empty($this->queryLimit)) {
            $query .= " LIMIT " . $this->queryLimit;
        }

        $statement = $this->getConnection->prepare($query);
        $statement->execute();
        $results = $statement->fetchAll(\PDO::FETCH_OBJ);

        $this->resetQuery();
        return $results ?: [];
    }


    /**
     * @param string $select
     * @return string
     *
     */
    private function buildBaseSelect(string $select): string
    {
        $query = "SELECT {$select} FROM {$this->table}";

        if (!empty($this->jointClause)) {
            $query .= implode(' ', $this->jointClause);
        }

        if ($this->onlyTrashed) {
            $this->whereClause[] = "{$this->columnName} IS NOT NULL";
        } elseif (!$this->withTrashed) {
            $this->whereClause[] = "{$this->columnName} IS NULL";
        }

        $query .= $this->whereBuild();

        return $query;
    }


    /**
     * @return string
     *
     *
     */
    protected function whereBuild() : string
    {
        $whereSql = '';
// Build AND group
        if (!empty($this->whereClause)) {
            $whereSql = '(' . implode(' AND ', $this->whereClause) . ')';
        }

// Build OR group
        if (!empty($this->orWhereClause)) {
            $orSql = '(' . implode(' OR ', $this->orWhereClause) . ')';
            if ($whereSql) {
                $whereSql .= " OR " . $orSql;
            } else {
                $whereSql = $orSql;
            }
        }
        // Attach to query
        if ($whereSql) {
            return " WHERE " . $whereSql;
        }

        return '';
    }

    /**
     * @return mixed|null
     *
     */
    public function first()
    {
        $this->queryLimit = 1;
        $results = $this->get();
        if (empty($results)) return null;
        return $results[0];
    }


    /**
     * @return array
     *
     */
    public function all(): array
    {
        return $this->get();
    }


    /**
     * @param array|string $column
     * @param $operator
     * @param $value
     * @return $this
     *
     */
    public function orWhere(array|string $column, $operator = null, $value = null): self
    {
        if (is_array($column)) {
            foreach ($column as $key => $value) {
                $this->orWhereClause[] = "$key = '$value'";
            }
        } else if (!empty($column) && !empty($operator) && !empty($value)) {
            $this->orWhereClause[] = "$column $operator '$value'";
        }
        return $this;
    }


    public function leftjoin(string $table_name, mixed $primary_key_table, mixed $foreign_key_table)
    {
        $this->jointClause[] = " LEFT JOIN $table_name ON $primary_key_table = $foreign_key_table";
        $this->jointTableClause[] = $table_name . ".*";
        return $this;
    }

    public function rightjoin(string $table_name, mixed $primary_key_table, mixed $foreign_key_table)
    {
        $this->jointClause[] = " RIGHT JOIN $table_name ON $primary_key_table = $foreign_key_table";
        $this->jointTableClause[] = $table_name . ".*";
        return $this;
    }

    public function crossjoin(string $table_name, mixed $primary_key_table, mixed $foreign_key_table)
    {
        $this->jointClause[] = " CROSS JOIN $table_name ON $primary_key_table = $foreign_key_table";
        $this->jointTableClause[] = $table_name . ".*";
        return $this;
    }

    public function innerjoin(string $table_name, mixed $primary_key_table, mixed $foreign_key_table)
    {
        $this->jointClause[] = " INNER JOIN $table_name ON $primary_key_table = $foreign_key_table";
        $this->jointTableClause[] = $table_name . ".*";
        return $this;
    }

    public function select(...$columns)
    {
        if (count($columns) === 1 && is_array($columns[0])) {
            $columns = $columns[0];
        }

        foreach ($columns as $column) {
            $this->selectClause[] = $column;
        }

        return $this;
    }


    /**
     * @return $this
     *
     */
    public function delete(): self
    {
        $where = $this->whereBuild();

        if (!$where) {
            throw new \Exception("Delete requires WHERE condition");
        }
        $query = "UPDATE  {$this->table} SET {$this->columnName} = '{$this->deletedAtTimestamp}' {$where}";
        $stmt = $this->getConnection->prepare($query);
        $stmt->execute();
        return $this;
    }


    /**
     * @return $this|self
     *
     * When you call this means you reffered to delete function
     */
    public function forceDelete()
    {
        $where = $this->whereBuild();

        if (!$where) {
            throw new \Exception("Delete requires WHERE condition");
        }
        $query = "DELETE FROM  {$this->table} {$where}";
        $stmt = $this->getConnection->prepare($query);
        $stmt->execute();
        return $this;
    }


    /**
     * @return $this
     * @throws \Exception
     *
     */
    public function softDeletes(): self
    {
        return $this->delete();
    }


    /**
     * @param ...$id
     * @return $this
     *
     */

    public function destroy(...$id): self
    {
        if (empty($id)) {
            throw new InvalidArgumentException("Please provide the ids to delete");
        }
        $placeholders = implode(',', array_fill(0, count($id), '?'));
        $query = "UPDATE {$this->table} SET {$this->columnName} = '{$this->deletedAtTimestamp}'";
        $query .= " WHERE id IN ($placeholders)";
        $stmt = $this->getConnection->prepare($query);
        $stmt->execute($id);
        return $this;
    }

    /**
     * @return $this
     *
     */
    public function restore(): self
    {
        $where = $this->whereBuild();

        if (!$where) {
            throw new \Exception("Restore requires WHERE condition");
        }

        $query = "UPDATE {$this->table} SET {$this->columnName} = NULL {$where}";
        $stmt = $this->getConnection->prepare($query);
        $stmt->execute();
        return $this;
    }


    public function onlyTrashed(): self
    {
        $this->onlyTrashed = true;
        return $this;
    }


    public function withTrashed(): self
    {
        $this->withTrashed = true;
        return $this;
    }


    private function resetQuery(): void
    {
        $this->whereClause = [];
        $this->orWhereClause = [];
        $this->jointClause = [];
        $this->jointTableClause = [];
        $this->selectClause = [];
        $this->queryLimit = null;
        $this->withTrashed = false;
        $this->onlyTrashed = false;
        $this->defaultQueryString = null;
        $this->isCount = false;
    }


    public function limit(int $limit, int $offset = 0): self
    {
        $this->queryLimit = " {$limit} OFFSET {$offset}";
        return $this;
    }

    /**
     * @return int|null
     *
     */
    public function count() : ?int
    {
        $query = $this->buildBaseSelect("COUNT(*) as total");

        $statement = $this->getConnection->prepare($query);
        $statement->execute();

        $result = $statement->fetch(\PDO::FETCH_OBJ);

        $this->resetQuery();

        return (int) ($result->total ?? 0);
    }

    public function paginate(int $perPage = 2, int $page = 1)
    {
        $this->row_per_page = $perPage;
        $this->current_page = $page;
        if(isset($_GET['page']))
        {
            $this->currentPage = $_GET['page'];
        }
        return $this->internalPaginator($this);
    }
}