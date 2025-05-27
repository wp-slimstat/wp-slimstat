<?php

namespace SlimStat\Utils;

use SlimStat\Utils\TransientCacheTrait;
use InvalidArgumentException;

/**
 * Query builder with transient cache support for SlimStat.
 * Usage: $query = new Query();
 *         $result = $query->select('table', ['field1', 'field2'])->where('field1', 'value')->get();
 */
class Query
{
    use TransientCacheTrait;

    private $queries = [];
    private $operation;
    private $table;
    private $fields = '*';
    private $subQuery;
    private $orderClause;
    private $groupByClause;
    private $limitClause;
    private $whereRelation = 'AND';
    private $setClauses = [];
    private $joinClauses = [];
    private $whereClauses = [];
    private $rawWhereClause = [];
    private $valuesToPrepare = [];
    private $allowCaching = false;
    private $cacheExpiration = 3600;

    protected $db;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    public static function select($fields = '*')
    {
        $instance = new self();
        $instance->operation = 'select';
        $instance->fields = is_array($fields) ? implode(', ', $fields) : $fields;
        return $instance;
    }

    public static function update($table)
    {
        $instance = new self();
        $instance->operation = 'update';
        $instance->table = $table;
        return $instance;
    }

    public static function delete($table)
    {
        $instance = new self();
        $instance->operation = 'delete';
        $instance->table = $table;
        return $instance;
    }

    public static function insert($table)
    {
        $instance = new self();
        $instance->operation = 'insert';
        $instance->table = $table;
        return $instance;
    }

    public static function union($queries)
    {
        $instance = new self();
        $instance->operation = 'union';
        $instance->queries = $queries;
        return $instance;
    }

    public function from($table)
    {
        $this->table = $table;
        return $this;
    }

    public function set($values)
    {
        if (empty($values)) return $this;
        foreach ($values as $field => $value) {
            $column = '`' . str_replace('`', '``', $field) . '`';
            if (is_string($value)) {
                $this->setClauses[] = "$column = %s";
                $this->valuesToPrepare[] = $value;
            } elseif (is_numeric($value)) {
                $this->setClauses[] = "$column = %d";
                $this->valuesToPrepare[] = $value;
            } elseif (is_null($value)) {
                $this->setClauses[] = "$column = NULL";
            }
        }
        return $this;
    }

    public function where($field, $operator, $value)
    {
        if (is_array($value)) {
            $value = array_filter(array_values($value));
        }
        if (!is_numeric($value) && empty($value)) return $this;
        $condition = $this->generateCondition($field, $operator, $value);
        if (!empty($condition)) {
            $this->whereClauses[] = $condition['condition'];
            $this->valuesToPrepare = array_merge($this->valuesToPrepare, $condition['values']);
        }
        return $this;
    }

    public function whereRaw($condition, $values = [])
    {
        if (!empty($values)) {
            $this->rawWhereClause[] = $this->prepareQuery($condition, $values);
        } else {
            $this->rawWhereClause[] = $condition;
        }
        return $this;
    }

    public function groupBy($fields)
    {
        if (is_array($fields)) {
            $fields = implode(', ', $fields);
        }
        if (!empty($fields)) {
            $this->groupByClause = "GROUP BY {$fields}";
        }
        return $this;
    }

    public function orderBy($fields, $order = 'DESC')
    {
        if (!in_array(strtoupper($order), ['ASC', 'DESC'])) {
            $order = 'DESC';
        }
        if (!empty($fields)) {
            if (is_string($fields)) {
                $fields = explode(',', $fields);
                $fields = array_map('trim', $fields);
            }
            if (is_array($fields)) {
                $orderParts = [];
                foreach ($fields as $field) {
                    $orderParts[] = "$field $order";
                }
                if (!empty($orderParts)) {
                    $this->orderClause = 'ORDER BY ' . implode(', ', $orderParts);
                }
            }
        }
        return $this;
    }

    public function limit($limit)
    {
        $this->limitClause = 'LIMIT ' . intval($limit);
        return $this;
    }

    public function perPage($page = 1, $perPage = 10)
    {
        $page = intval($page);
        $perPage = intval($perPage);
        if ($page > 0 && $perPage > 0) {
            $offset = ($page - 1) * $perPage;
            $this->limitClause = "LIMIT {$perPage} OFFSET {$offset}";
        }
        return $this;
    }

    public function join($table, $on, $conditions = [], $joinType = 'INNER')
    {
        if (is_array($on) && count($on) == 2) {
            $joinClause = "$joinType JOIN $table ON {$on[0]} = {$on[1]}";
            if (!empty($conditions)) {
                foreach ($conditions as $condition) {
                    $field = $condition[0];
                    $operator = $condition[1];
                    $value = $condition[2];
                    $cond = $this->generateCondition($field, $operator, $value);
                    if (!empty($cond)) {
                        $joinClause .= " AND {$cond['condition']}";
                        $this->valuesToPrepare = array_merge($this->valuesToPrepare, $cond['values']);
                    }
                }
            }
            $this->joinClauses[] = $joinClause;
        } else {
            throw new InvalidArgumentException('Invalid join clause');
        }
        return $this;
    }

    public function allowCaching($flag = true, $expiration = 3600)
    {
        $this->allowCaching = $flag;
        $this->cacheExpiration = $expiration;
        return $this;
    }

    /**
     * Enable caching if the date range does not include today.
     * @param string $to End date (Y-m-d or Y-m-d H:i:s)
     * @return void
     */
    public function canUseCacheForDateRange($to)
    {
        $today = $this->getTodayDate();
        // Cache should be used if the date range does not include today
        if ($to < $today) {
            $this->allowCaching(true, $this->cacheExpiration);
        }
    }

    /**
     * Get today's date in Y-m-d format (server time).
     * @return string
     */
    protected function getTodayDate()
    {
        return date('Y-m-d');
    }

    protected function generateCondition($field, $operator, $value)
    {
        $condition = '';
        $values = [];
        switch ($operator) {
            case '=':
            case '!=':
            case '>':
            case '>=':
            case '<':
            case '<=':
            case 'LIKE':
            case 'NOT LIKE':
                $condition = "$field $operator %s";
                $values[] = $value;
                break;
            case 'IN':
            case 'NOT IN':
                if (is_string($value)) {
                    $value = explode(',', $value);
                }
                if (!empty($value) && is_array($value) && count($value) == 1) {
                    $operator = ($operator == 'IN') ? '=' : '!=';
                    return $this->generateCondition($field, $operator, reset($value));
                }
                if (!empty($value) && is_array($value)) {
                    $placeholders = implode(', ', array_fill(0, count($value), '%s'));
                    $condition = "$field $operator ($placeholders)";
                    $values = $value;
                }
                break;
            case 'BETWEEN':
                if (is_array($value) && count($value) === 2) {
                    $condition = "$field BETWEEN %s AND %s";
                    $values = $value;
                }
                break;
            default:
                throw new InvalidArgumentException("Unsupported operator: $operator");
        }
        if (empty($condition)) return;
        return [
            'condition' => $condition,
            'values' => $values
        ];
    }

    protected function buildQuery()
    {
        switch ($this->operation) {
            case 'select':
                $query = "SELECT $this->fields FROM $this->table";
                break;
            case 'update':
                $query = "UPDATE $this->table SET " . implode(', ', $this->setClauses);
                break;
            case 'delete':
                $query = "DELETE FROM $this->table";
                break;
            case 'insert':
                // Not implemented: for insert, use $wpdb->insert directly
                $query = '';
                break;
            case 'union':
                $query = implode(' UNION ', $this->queries);
                break;
            default:
                throw new InvalidArgumentException('Unknown operation');
        }
        if (!empty($this->joinClauses)) {
            $query .= ' ' . implode(' ', $this->joinClauses);
        }
        if (!empty($this->whereClauses)) {
            $query .= ' WHERE ' . implode(" $this->whereRelation ", $this->whereClauses);
        }
        if (!empty($this->rawWhereClause)) {
            $query .= empty($this->whereClauses) ? ' WHERE ' : ' ';
            $query .= implode(' ', $this->rawWhereClause);
        }
        if (!empty($this->groupByClause)) {
            $query .= ' ' . $this->groupByClause;
        }
        if (!empty($this->orderClause)) {
            $query .= ' ' . $this->orderClause;
        }
        if (!empty($this->limitClause)) {
            $query .= ' ' . $this->limitClause;
        }
        return $query;
    }

    protected function prepareQuery($query, $args = [])
    {
        if (preg_match('/%[i|s|f|d]/', $query)) {
            $query = $this->db->prepare($query, $args);
        }
        return $query;
    }

    public function getAll()
    {
        $query = $this->buildQuery();
        $query = $this->prepareQuery($query, $this->valuesToPrepare);
        if ($this->allowCaching) {
            $cachedResult = $this->getCachedResult($query);
            if ($cachedResult !== false) {
                return $cachedResult;
            }
        }
        $result = $this->db->get_results($query, ARRAY_A); // Always return array results
        if ($this->allowCaching) {
            $this->setCachedResult($query, $result, $this->cacheExpiration);
        }
        return $result;
    }

    public function getVar()
    {
        $query = $this->buildQuery();
        $query = $this->prepareQuery($query, $this->valuesToPrepare);
        if ($this->allowCaching) {
            $cachedResult = $this->getCachedResult($query);
            if ($cachedResult !== false) {
                return $cachedResult;
            }
        }
        $result = $this->db->get_var($query);
        if ($this->allowCaching) {
            $this->setCachedResult($query, $result, $this->cacheExpiration);
        }
        return $result;
    }

    public function getRow()
    {
        $query = $this->buildQuery();
        $query = $this->prepareQuery($query, $this->valuesToPrepare);
        if ($this->allowCaching) {
            $cachedResult = $this->getCachedResult($query);
            if ($cachedResult !== false) {
                return $cachedResult;
            }
        }
        $result = $this->db->get_row($query);
        if ($this->allowCaching) {
            $this->setCachedResult($query, $result, $this->cacheExpiration);
        }
        return $result;
    }

    public function getCol()
    {
        $query = $this->buildQuery();
        $query = $this->prepareQuery($query, $this->valuesToPrepare);
        if ($this->allowCaching) {
            $cachedResult = $this->getCachedResult($query);
            if ($cachedResult !== false) {
                return $cachedResult;
            }
        }
        $result = $this->db->get_col($query);
        if ($this->allowCaching) {
            $this->setCachedResult($query, $result, $this->cacheExpiration);
        }
        return $result;
    }

    /**
     * Add a date range condition and enable cache if possible.
     * @param string $field
     * @param array|string $date
     * @return $this
     */
    public function whereDate($field, $date)
    {
        if (empty($date)) return $this;
        if (is_array($date)) {
            $from = isset($date['from']) ? $date['from'] : '';
            $to   = isset($date['to']) ? $date['to'] : '';
        } elseif (is_string($date)) {
            // Simple string, treat as a single day
            $from = $date;
            $to = $date;
        } else {
            return $this;
        }
        if (!empty($from) && !empty($to)) {
            if (strlen($from) === 10) $from .= ' 00:00:00';
            if (strlen($to) === 10) $to .= ' 23:59:59';
            $this->whereClauses[] = "$field BETWEEN %s AND %s";
            $this->valuesToPrepare[] = $from;
            $this->valuesToPrepare[] = $to;
            $this->canUseCacheForDateRange($to);
        }
        return $this;
    }
}
