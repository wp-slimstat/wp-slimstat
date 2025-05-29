<?php

namespace SlimStat\Utils;

use SlimStat\Utils\TransientCacheTrait;
use InvalidArgumentException;

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

    private $_isLiveQuery = false;

    /**
     * Constructor.
     *
     * Initializes the query with the global $wpdb instance.
     */
    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * Initializes a new query instance for a select operation on a table.
     *
     * @param string|array $fields The fields to select. If an array is provided, the fields are
     *                              concatenated with a comma separator and the resulting string
     *                              is used as the SELECT clause.
     * @return static A new Query instance configured for a select operation.
     */
    public static function select($fields = '*')
    {
        $instance = new self();
        $instance->operation = 'select';
        $instance->fields = is_array($fields) ? implode(', ', $fields) : $fields;
        return $instance;
    }

    /**
     * Initializes a new query instance for an update operation on the specified table.
     *
     * @param string $table The name of the table to update.
     *
     * @return static A new Query instance configured for an update operation.
     */
    public static function update($table)
    {
        $instance = new self();
        $instance->operation = 'update';
        $instance->table = $table;
        return $instance;
    }

    /**
     * Initializes a new query instance for a delete operation on the specified table.
     *
     * @param string $table The name of the table to delete from.
     * @return static
     */
    public static function delete($table)
    {
        $instance = new self();
        $instance->operation = 'delete';
        $instance->table = $table;
        return $instance;
    }

    /**
     * Initializes a new query instance for an insert operation on the specified table.
     *
     * @param string $table The name of the table to insert data into.
     *
     * @return self A new Query instance configured for an insert operation.
     */
    public static function insert($table)
    {
        $instance = new self();
        $instance->operation = 'insert';
        $instance->table = $table;
        return $instance;
    }

    /**
     * Combines multiple query instances into a single UNION query.
     *
     * @param array $queries An array of Query instances to be united.
     *
     * @return self A new Query instance representing the UNION of the provided queries.
     */
    public static function union($queries)
    {
        $instance = new self();
        $instance->operation = 'union';
        $instance->queries = $queries;
        return $instance;
    }

    /**
     * Specifies the table to be used in the query.
     *
     * @param string $table The name of the table to use.
     *
     * @return $this
     */
    public function from($table)
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Sets the values for the columns in the current query.
     *
     * This function prepares the column assignments for an SQL update operation.
     * It supports string, numeric, and null values, and automatically escapes
     * field names to prevent SQL injection.
     *
     * @param array $values An associative array of column-value pairs to set.
     *                       The array key is the column name, and the value is
     *                       the value to assign to the column.
     *
     * @return $this
     */
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

    /**
     * Add a WHERE clause to the query.
     *
     * @param string $field The field to filter on.
     * @param string $operator The operator to use. Supported operators: =, !=, >, >=, <, <=, LIKE, NOT LIKE, IN, NOT IN, BETWEEN.
     * @param mixed $value The value to filter on. Can be a string, int, array or null.
     * @return $this
     *
     * @throws InvalidArgumentException If the operator is not supported.
     */
    public function where($field, $operator, $value)
    {
        if (strtoupper($operator) === 'BETWEEN' && is_array($value) && count($value) === 2 && ($value[0] !== null && $value[1] !== null)) {
            $condition = $this->generateCondition($field, $operator, $value);
            if (!empty($condition)) {
                $this->whereClauses[] = $condition['condition'];
                $this->valuesToPrepare = array_merge($this->valuesToPrepare, $condition['values']);
            }
            return $this;
        }
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

    /**
     * Add a raw WHERE clause to the query. If values are provided, they will be
     * escaped and inserted into the query.
     *
     * @param string $condition The raw WHERE condition.
     * @param array $values Values to be inserted into the condition.
     * @return $this
     */
    public function whereRaw($condition, $values = [])
    {
        if (!empty($values)) {
            $this->rawWhereClause[] = $this->prepareQuery($condition, $values);
        } else {
            $this->rawWhereClause[] = $condition;
        }
        return $this;
    }

    /**
     * Sets the GROUP BY clause for the query.
     *
     * @param string|array $fields The fields to group by. Can be a comma-separated string or an array of fields.
     *
     * @return $this
     */
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

    /**
     * Sets the ORDER BY clause for the query.
     *
     * @param string|array $fields The fields to order by. Can be a comma-separated string or an array of fields.
     * @param string $order The order direction, either 'ASC' or 'DESC'. Defaults to 'DESC'.
     *
     * @return $this
     */
    public function orderBy($fields, $order = 'DESC')
    {
        if (empty($fields)) return $this;
        if (is_string($fields)) {
            if (preg_match('/\b(ASC|DESC)\b/i', $fields)) {
                $this->orderClause = 'ORDER BY ' . $fields;
                return $this;
            }
            $fields = explode(',', $fields);
            $fields = array_map('trim', $fields);
        }
        if (is_array($fields)) {
            $order = strtoupper($order);
            if (!in_array($order, ['ASC', 'DESC'])) {
                $order = 'DESC';
            }
            $orderParts = [];
            foreach ($fields as $field) {
                $orderParts[] = "$field $order";
            }
            if (!empty($orderParts)) {
                $this->orderClause = 'ORDER BY ' . implode(', ', $orderParts);
            }
        }
        return $this;
    }

    /**
     * Sets the LIMIT clause for the query.
     *
     * @param int $limit The maximum number of results to return.
     *
     * @return $this
     */
    public function limit($limit)
    {
        $this->limitClause = 'LIMIT ' . intval($limit);
        return $this;
    }

    /**
     * Sets the LIMIT and OFFSET clauses for pagination.
     *
     * @param int $page The page number. Defaults to 1.
     * @param int $perPage The number of results to show per page. Defaults to 10.
     *
     * @return $this
     */
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

    /**
     * Join another table.
     *
     * @param string $table The table to join.
     * @param string|array $on The join condition. Can be an array with two fields to join on, or a string with a condition.
     * @param array $conditions An array of conditions to join on. Each condition is an array with three elements: field, operator, value.
     * @param string $joinType The type of join. Can be INNER, LEFT, or RIGHT. Defaults to INNER.
     *
     * @return $this
     *
     * @throws InvalidArgumentException If the join condition is invalid.
     */
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

    /**
     * Set the caching flag and expiration time.
     *
     * @param bool $flag Whether to allow caching.
     * @param int $expiration The cache expiration time in seconds.
     *
     * @return $this
     */
    public function allowCaching($flag = true, $expiration = 3600)
    {
        $this->allowCaching = $flag;
        $this->cacheExpiration = $expiration;
        return $this;
    }


    /**
     * Set the caching flag depending on whether the given date range overlaps with today.
     *
     * If the given date range is entirely in the past, caching is allowed. Otherwise, caching is disabled.
     *
     * @param int|string $to The end date of the range (Y-m-d or Y-m-d H:i:s or timestamp)
     *
     * @return $this
     */
    public function canUseCacheForDateRange($to)
    {
        $today = $this->getTodayDate();
        $toTs  = is_numeric($to) ? intval($to) : strtotime($to);

        if ($toTs < $today) {
            $this->allowCaching(true, $this->cacheExpiration);
        } else {
            $this->allowCaching(false);
        }
    }


    /**
     * Get the timestamp for the start of today.
     *
     * @return int The timestamp for the start of today (midnight).
     */
    protected function getTodayDate()
    {
        return strtotime(date('Y-m-d 00:00:00'));
    }

    /**
     * Analyzes the WHERE clauses to detect date ranges that overlap with today.
     *
     * This function iterates through the WHERE clauses to find any clause that specifies
     * a date range (using "BETWEEN %s AND %s") and determines if this range overlaps
     * with today. It extracts the timestamps for the start and end of the historical
     * period (up to the start of today) and the live period (starting today).
     *
     * @return array<int|bool|null> An array containing:
     * - boolean: whether a split range was found
     * - int|null: historical start timestamp
     * - int|null: historical end timestamp (inclusive)
     * - int|null: live start timestamp (inclusive)
     * - int|null: live end timestamp
     */
    protected function getSplitDateRanges2()
    {
        $dtField = 'dt';
        $todayStart = $this->getTodayDate();
        $now = time();
        foreach ($this->whereClauses as $idx => $clause) {
            if (preg_match('/' . $dtField . ' BETWEEN %s AND %s/', $clause)) {
                $from = null;
                $to = null;
                $dtIdx = 0;
                foreach ($this->whereClauses as $i => $c) {
                    if ($i == $idx) break;
                    if (preg_match('/%s/', $c)) $dtIdx += substr_count($c, '%s');
                }
                $from = $this->valuesToPrepare[$dtIdx] ?? null;
                $to = $this->valuesToPrepare[$dtIdx+1] ?? null;
                $fromTs = is_numeric($from) ? intval($from) : strtotime($from);
                $toTs = is_numeric($to) ? intval($to) : strtotime($to);
                if ($fromTs !== null && $toTs !== null && $fromTs < $todayStart && $toTs >= $todayStart) {
                    return [true, $fromTs, $todayStart - 1, $todayStart, $toTs];
                }
            }
        }
        return [false, null, null, null, null];
    }

    /**
     * Helper: Generate a SQL condition based on the given field, operator and value.
     *
     * Supported operators: =, !=, >, >=, <, <=, LIKE, NOT LIKE, IN, NOT IN, BETWEEN
     *
     * @param string $field Field name
     * @param string $operator SQL operator
     * @param mixed $value Value to be used in the condition. Can be a string, int, array or null.
     * @return array|false Array with keys 'condition' and 'values', or false if the condition could not be generated.
     * @throws InvalidArgumentException If the operator is not supported.
     */
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

    /**
     * Builds and returns an SQL query string based on the current operation and clauses.
     *
     * This function constructs a SQL query by assembling various parts such as the
     * operation type (select, update, delete, insert, union), join clauses, where
     * clauses, group by, order by, and limit clauses. It supports conditional logic
     * to append appropriate SQL syntax based on the operation and provided clauses.
     *
     * @return string The constructed SQL query string.
     * @throws InvalidArgumentException If the operation type is unknown.
     */
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
            if (!empty($this->whereClauses)) {
                $query .= ' AND ' . implode(' ', $this->rawWhereClause);
            } else {
                $query .= ' WHERE ' . implode(' ', $this->rawWhereClause);
            }
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

    /**
     * Prepares a query for execution by replacing placeholders with actual values.
     * Supported placeholders are %i, %s, %f, and %d.
     * If the query contains more than one placeholder, the $args parameter should be an array with the same number of elements.
     * If the query contains only one placeholder, the $args parameter can be either an array or a single value.
     * If the query contains no placeholders, the $args parameter is ignored.
     * @param string $query
     * @param array $args
     * @return string The prepared query
     */
    protected function prepareQuery($query, $args = [])
    {
        if (preg_match('/%[i|s|f|d]/', $query)) {
            $placeholder_count = preg_match_all('/%[i|s|f|d]/', $query, $matches);
            $args_count = is_array($args) ? count($args) : (empty($args) ? 0 : 1);
            if ($placeholder_count === 1) {
                if (is_array($args)) {
                    $query = $this->db->prepare($query, reset($args));
                } else {
                    $query = $this->db->prepare($query, $args);
                }
            } elseif (is_array($args) && $args_count === $placeholder_count) {
                $query = $this->db->prepare($query, $args);
            } else {
                return $query;
            }
        }
        return $query;
    }

    /**
     * Generates a cache key for a given query and its arguments.
     *
     * This method serializes the query and arguments into a data array,
     * creates an MD5 hash of the serialized data, and returns a truncated
     * hash as a unique cache key prefixed with 'wp_slimstat_query_'.
     *
     * @param string $query The SQL query.
     * @param array $args The query arguments.
     * @return string The generated cache key.
     */
    protected function getCacheKeyForQuery($query, $args = [])
    {
        $data = [
            'query' => $query,
            'args' => $args,
        ];
        $hash = substr(md5(serialize($data)), 0, 16);
        return 'wp_slimstat_query_' . $hash;
    }


    /**
     * Retrieves the cached result for the given query and args
     *
     * @param string $query  The SQL query
     * @param array  $args   The query arguments
     *
     * @return mixed The query result, or false if there is no cached result
     */
    protected function getCachedResultForQuery($query, $args = [])
    {
        $cacheKey = $this->getCacheKeyForQuery($query, $args);
        $data = get_transient($cacheKey);
        if ($data === false) return false;
        if (is_array($data) && isset($data['chunks']) && isset($data['size'])) {
            $chunks = [];
            for ($i = 0; $i < $data['chunks']; $i++) {
                $chunk = get_transient($cacheKey . '_' . $i);
                if ($chunk === false) return false;
                $chunks[] = $chunk;
            }
            $data = implode('', $chunks);
        } elseif (is_array($data)) {
            $data = serialize($data);
        }
        if (function_exists('gzuncompress') && is_string($data)) {
            $first2 = substr($data, 0, 2);
            if ($first2 === "\x1f\x8b" || $first2 === "\x78\x9c" || $first2 === "\x78\xda") {
                $data = @gzuncompress($data);
            }
        }
        $result = @unserialize($data);
        return $result;
    }

    /**
     * Sets the transient cache for the given query and args
     *
     * @param string $query  The SQL query
     * @param array  $args   The query arguments
     * @param mixed  $result The query result
     * @param int    $expiration The cache expiration time, in seconds
     *
     * @return bool True if cache was successfully set, false otherwise
     */
    protected function setCachedResultForQuery($query, $args, $result, $expiration = 300)
    {
        $cacheKey = $this->getCacheKeyForQuery($query, $args);
        $data = serialize($result);

        $max_chunk_size = 900 * 1024; // 900KB
        $old_meta = get_transient($cacheKey);
        if (is_array($old_meta) && isset($old_meta['chunks'])) {
            for ($i = 0; $i < $old_meta['chunks']; $i++) {
                delete_transient($cacheKey . '_' . $i);
            }
        }
        if (strlen($data) > $max_chunk_size) {
            $chunks = str_split($data, $max_chunk_size);
            $meta = [
                'chunks' => count($chunks),
                'size' => strlen($data)
            ];
            if (strlen(serialize($meta)) > $max_chunk_size) {
                return false;
            }
            set_transient($cacheKey, $meta, $expiration);
            foreach ($chunks as $i => $chunk) {
                set_transient($cacheKey . '_' . $i, $chunk, $expiration);
            }
        } else {
            set_transient($cacheKey, $data, $expiration);
        }
        return true;
    }


    /**
     * Extracts a date range from the WHERE clause where the range overlaps with today.
     * Returns an array with the following elements:
     * - boolean: whether a split range was found
     * - int: historical start timestamp
     * - int: historical end timestamp (inclusive)
     * - int: live start timestamp (inclusive)
     * - int: live end timestamp
     * - int: index of the date field in the WHERE clause
     * - int: index of the WHERE clause with the date range
     * @return array<int, int, int, int, int, int, int>
     */
    protected function getSplitDateRanges()
    {
        $dtField = 'dt';
        $todayStart = $this->getTodayDate();
        foreach ($this->whereClauses as $idx => $clause) {
            if (preg_match('/' . $dtField . ' BETWEEN %s AND %s/', $clause)) {
                $dtIdx = 0;
                foreach ($this->whereClauses as $i => $c) {
                    if ($i == $idx) break;
                    if (preg_match('/%s/', $c)) $dtIdx += substr_count($c, '%s');
                }
                $from = $this->valuesToPrepare[$dtIdx] ?? null;
                $to = $this->valuesToPrepare[$dtIdx+1] ?? null;
                $fromTs = is_numeric($from) ? intval($from) : strtotime($from);
                $toTs = is_numeric($to) ? intval($to) : strtotime($to);
                if ($fromTs !== null && $toTs !== null && $fromTs < $todayStart && $toTs >= $todayStart) {
                    return [true, $fromTs, $todayStart - 1, $todayStart, $toTs, $dtIdx, $idx];
                }
            }
        }
        return [false, null, null, null, null, null, null];
    }


    /**
     * Merges two arrays of result rows from the historical and live parts of a query.
     * If $groupKey is set, the function will group the results by this key and sum the
     * values in $sumFields for each group. Otherwise, the function will return the
     * array merge of the two arrays.
     *
     * @param array $historical The result rows from the historical part of the query
     * @param array $live The result rows from the live part of the query
     * @param string $groupKey The key to group the results by
     * @param array $sumFields The fields to sum for each group
     *
     * @return array The merged and grouped result rows
     */
    protected function mergeGroupResults($historical, $live, $groupKey = null, $sumFields = ['counthits'])
    {
        $historical = is_array($historical) ? $historical : [];
        $live = is_array($live) ? $live : [];
        if (!$groupKey) return array_merge($historical, $live);
        $result = [];
        foreach ($historical as $row) {
            $key = $row[$groupKey];
            $result[$key] = $row;
        }
        foreach ($live as $row) {
            $key = $row[$groupKey];
            if (isset($result[$key])) {
                foreach ($sumFields as $field) {
                    if (isset($row[$field])) {
                        $result[$key][$field] += $row[$field];
                    }
                }
            } else {
                $result[$key] = $row;
            }
        }
        return array_values($result);
    }

    /**
     * Helper: Extract date ranges from WHERE clauses
     *
     * @return array Array of extracted date ranges with keys from, to, clauseIdx, and valueIdx
     */
    protected function extractDateRangesFromWhere() {
        $dtField = 'dt';
        $ranges = [];
        $dtIdx = 0;
        foreach ($this->whereClauses as $idx => $clause) {
            if (preg_match('/' . $dtField . ' BETWEEN %s AND %s/', $clause)) {
                $from = $this->valuesToPrepare[$dtIdx] ?? null;
                $to = $this->valuesToPrepare[$dtIdx+1] ?? null;
                $ranges[] = [
                    'from' => $from,
                    'to' => $to,
                    'clauseIdx' => $idx,
                    'valueIdx' => $dtIdx
                ];
            }
            if (preg_match_all('/%s/', $clause, $m)) {
                $dtIdx += count($m[0]);
            }
        }
        return $ranges;
    }

    /**
     * Helper: Process a date range query, splitting it into historical and live parts as needed.
     * @param int|string $from Start date (Y-m-d or Y-m-d H:i:s or timestamp)
     * @param int|string $to End date (Y-m-d or Y-m-d H:i:s or timestamp)
     * @param array $baseWhereClauses where clauses to use for the query
     * @param array $baseValuesToPrepare values to prepare for the query
     * @return array result set
     */
    protected function processDateRange($from, $to, $baseWhereClauses, $baseValuesToPrepare) {
        $todayStart = $this->getTodayDate();
        $fromTs = is_numeric($from) ? intval($from) : strtotime($from);
        $toTs = is_numeric($to) ? intval($to) : strtotime($to);

        if ($fromTs >= $todayStart) {
            $liveQuery = clone $this;
            $liveQuery->whereClauses = $baseWhereClauses;
            $liveQuery->valuesToPrepare = $baseValuesToPrepare;
            $liveQuery->whereDate('dt', ['from' => $fromTs, 'to' => $toTs], true);
            $liveQuery->allowCaching(false, 0);
            return $liveQuery->getAll();
        }

        if ($toTs < $todayStart) {
            $cacheQuery = clone $this;
            $cacheQuery->whereClauses = $baseWhereClauses;
            $cacheQuery->valuesToPrepare = $baseValuesToPrepare;
            $cacheQuery->whereDate('dt', ['from' => $fromTs, 'to' => $toTs]);
            $cacheQuery->allowCaching(true, $this->cacheExpiration);
            return $cacheQuery->getAll();
        }

        $histQuery = clone $this;
        $histQuery->whereClauses = $baseWhereClauses;
        $histQuery->valuesToPrepare = $baseValuesToPrepare;
        $histQuery->whereDate('dt', ['from' => $fromTs, 'to' => $todayStart - 1]);
        $histQuery->allowCaching(true, $this->cacheExpiration);
        $historical = $histQuery->getAll();

        $liveQuery = clone $this;
        $liveQuery->whereClauses = $baseWhereClauses;
        $liveQuery->valuesToPrepare = $baseValuesToPrepare;
        $liveQuery->whereDate('dt', ['from' => $todayStart, 'to' => $toTs], true);
        $liveQuery->allowCaching(false, 0);
        $live = $liveQuery->getAll();

        if ($toTs == $todayStart) {
            return $historical;
        }
        if ($todayStart - 1 < $fromTs) {
            return $live;
        }
        return array_merge($historical, $live);
    }


    /**
     * Get all results from a query.
     * If this is a live query (i.e. the query has a live date range), this function will
     * split the query into two parts: a historical part that can be safely cached, and a live
     * part that should not be cached.
     * If this is not a live query, the function will simply return the result of the query.
     * @return array The result of the query
     */
    public function getAll()
    {
        if (isset($this->_isLiveQuery) && $this->_isLiveQuery) {
            $query = $this->buildQuery();
            $query = $this->prepareQuery($query, $this->valuesToPrepare);
            $result = $this->db->get_results($query, ARRAY_A);
            return $result;
        }
        $ranges = $this->extractDateRangesFromWhere();
        if (count($ranges) > 1) {
            $results = [];
            foreach ($ranges as $range) {
                if (empty($range['from']) || empty($range['to'])) {
                    continue;
                }
                $baseWhereClauses = $this->whereClauses;
                $baseValuesToPrepare = $this->valuesToPrepare;
                array_splice($baseWhereClauses, $range['clauseIdx'], 1);
                array_splice($baseValuesToPrepare, $range['valueIdx'], 2);
                $data = $this->processDateRange($range['from'], $range['to'], $baseWhereClauses, $baseValuesToPrepare);
                if (is_array($data)) {
                    $results = array_merge($results, $data);
                }
            }
            return $results;
        }

        list($split, $histFrom, $histTo, $liveFrom, $liveTo, $dtIdx, $dtClauseIdx) = $this->getSplitDateRanges();
        if ($split) {
            $baseWhereClauses = $this->whereClauses;
            $baseValuesToPrepare = $this->valuesToPrepare;
            array_splice($baseWhereClauses, $dtClauseIdx, 1);
            $baseValues = $baseValuesToPrepare;
            array_splice($baseValues, $dtIdx, 2);

            // Clone for historical
            $histQuery = clone $this;
            $histQuery->whereClauses = $baseWhereClauses;
            $histQuery->valuesToPrepare = $baseValues;
            $histQuery->whereDate('dt', ['from' => $histFrom, 'to' => $histTo]);
            $histQuery->allowCaching(true, $this->cacheExpiration);
            try {
                $historical = $histQuery->getAll();
            } catch (Exception $e) {
                $historical = [];
            }

            // Clone for live
            $liveQuery = clone $this;
            $liveQuery->whereClauses = $baseWhereClauses;
            $liveQuery->valuesToPrepare = $baseValues;
            $liveQuery->whereDate('dt', ['from' => $liveFrom, 'to' => $liveTo], true);
            $liveQuery->allowCaching(false, 0);
            try {
                $live = $liveQuery->getAll();
            } catch (Exception $e) {
                $live = [];
            }

            if (is_array($live)) {
                $dtList = array_map(fn($row) => $row['dt'] ?? null, $live);
            }

            $groupKey = null;
            if (!empty($this->groupByClause)) {
                if (preg_match('/GROUP BY ([a-zA-Z0-9_]+)/', $this->groupByClause, $m)) {
                    $groupKey = $m[1];
                }
            }
            $merged = $this->mergeGroupResults($live, $historical, $groupKey);
            if (is_array($merged)) {
                $dtList = array_map(fn($row) => $row['dt'] ?? null, $merged);
            }
            return $merged;
        }
        $query = $this->buildQuery();
        $query = $this->prepareQuery($query, $this->valuesToPrepare);
        if ($this->allowCaching) {
            try {
                $cachedResult = $this->getCachedResultForQuery($query, $this->valuesToPrepare);
            } catch (Exception $e) {
                $cachedResult = false;
            }
            if ($cachedResult !== false) {
                return $cachedResult;
            }
        }
        try {
            $result = $this->db->get_results($query, ARRAY_A);
        } catch (Exception $e) {
            $result = [];
        }
        if ($this->allowCaching) {
            try {
                $this->setCachedResultForQuery($query, $this->valuesToPrepare, $result, $this->cacheExpiration);
            } catch (Exception $e) {
                // ignore
            }
        }
        return $result;
    }

    /**
     * Execute the query and return a single value from the first row
     *
     * This is a shortcut for `getAll()[0][0]`
     *
     * @return mixed The value, or false/null if no rows are returned
     */
    public function getVar()
    {
        $query = $this->buildQuery();
        $query = $this->prepareQuery($query, $this->valuesToPrepare);
        if ($this->allowCaching) {
            $cachedResult = $this->getCachedResultForQuery($query, $this->valuesToPrepare);
            if ($cachedResult !== false) {
                return $cachedResult;
            }
        }
        $result = $this->db->get_var($query);
        if ($this->allowCaching) {
            $this->setCachedResultForQuery($query, $this->valuesToPrepare, $result, $this->cacheExpiration);
        }
        return $result;
    }

    /**
     * Execute the query and return a single row
     *
     * This is a shortcut for `getAll()[0]`
     *
     * @return array The row, or false/null if no rows are returned
     */
    public function getRow()
    {
        $query = $this->buildQuery();
        $query = $this->prepareQuery($query, $this->valuesToPrepare);
        if ($this->allowCaching) {
            $cachedResult = $this->getCachedResultForQuery($query, $this->valuesToPrepare);
            if ($cachedResult !== false) {
                return $cachedResult;
            }
        }
        $result = $this->db->get_row($query);
        if ($this->allowCaching) {
            $this->setCachedResultForQuery($query, $this->valuesToPrepare, $result, $this->cacheExpiration);
        }
        return $result;
    }

    /**
     * Execute the query and return a single column
     *
     * This is a shortcut for `getAll()`
     *
     * @return array The column, or false/null if no columns are returned
     */
    public function getCol()
    {
        $query = $this->buildQuery();
        $query = $this->prepareQuery($query, $this->valuesToPrepare);
        if ($this->allowCaching) {
            $cachedResult = $this->getCachedResultForQuery($query, $this->valuesToPrepare);
            if ($cachedResult !== false) {
                return $cachedResult;
            }
        }
        $result = $this->db->get_col($query);
        if ($this->allowCaching) {
            $this->setCachedResultForQuery($query, $this->valuesToPrepare, $result, $this->cacheExpiration);
        }
        return $result;
    }

    /**
     * Check if a where clause for a field/operator exists (e.g. 'dt BETWEEN').
     * @param string $field
     * @param string|null $operator
     * @return bool
     */
    public function hasWhereClause($field, $operator = null)
    {
        foreach ($this->whereClauses as $clause) {
            if ($operator) {
                if (stripos($clause, "$field $operator") !== false) {
                    return true;
                }
            } else {
                if (stripos($clause, $field) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Add a date range condition and enable cache if possible.
     * @param string $field
     * @param array|string $date
     * @return $this
     */
    public function whereDate($field, $date, $isLiveQuery = false)
    {
        if (empty($date)) return $this;

        if (is_array($date)) {
            $from = isset($date['from']) ? $date['from'] : '';
            $to   = isset($date['to']) ? $date['to'] : '';
        } elseif (is_string($date)) {
            $from = $date;
            $to = $date;
        } else {
            return $this;
        }

        if ($field === 'dt') {
            if (!empty($from) && !empty($to)) {
                $fromTs = is_numeric($from) ? intval($from) : strtotime($from);
                $toTs   = is_numeric($to) ? intval($to) : strtotime($to);

                $this->whereClauses[] = "$field BETWEEN %s AND %s";
                $this->valuesToPrepare[] = $fromTs;
                $this->valuesToPrepare[] = $toTs;
                $this->canUseCacheForDateRange($toTs);
                if ($isLiveQuery) {
                    $this->_isLiveQuery = true;
                }
            }
        } else {
            if (!empty($from) && !empty($to)) {
                if (strlen($from) === 10) $from .= ' 00:00:00';
                if (strlen($to) === 10) $to .= ' 23:59:59';
                $this->whereClauses[] = "$field BETWEEN %s AND %s";
                $this->valuesToPrepare[] = $from;
                $this->valuesToPrepare[] = $to;
                $this->canUseCacheForDateRange($to);
                if ($isLiveQuery) {
                    $this->_isLiveQuery = true;
                }
            }
        }
        return $this;
    }
}
