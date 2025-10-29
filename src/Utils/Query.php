<?php

namespace SlimStat\Utils;

use InvalidArgumentException;

class Query
{
    // Caching helpers inlined to avoid traits

    private $queries = [];

    private $operation;

    private $table;

    private $fields = '*';

    private $orderClause;

    private $groupByClause;

    private $havingClauses = [];

    private $limitClause;

    private $whereRelation = 'AND';

    private $setClauses = [];

    private $joinClauses = [];

    private $whereClauses = [];

    private $rawWhereClause = [];

    private $valuesToPrepare = [];

    private $insertValues = [];

    private $ignore = false;

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
     *                             concatenated with a comma separator and the resulting string
     *                             is used as the SELECT clause.
     *
     * @return static A new Query instance configured for a select operation.
     */
    public static function select($fields = '*')
    {
        $instance            = new self();
        $instance->operation = 'select';
        $instance->fields    = is_array($fields) ? implode(', ', $fields) : $fields;
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
        $instance            = new self();
        $instance->operation = 'update';
        $instance->table     = $table;
        return $instance;
    }

    /**
     * Initializes a new query instance for a delete operation on the specified table.
     *
     * @param string $table The name of the table to delete from.
     *
     * @return static
     */
    public static function delete($table)
    {
        $instance            = new self();
        $instance->operation = 'delete';
        $instance->table     = $table;
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
        $instance            = new self();
        $instance->operation = 'insert';
        $instance->table     = $table;
        return $instance;
    }

    /**
     * Adds IGNORE to the query.
     *
     * @param bool $ignore
     * @return $this
     */
    public function ignore($ignore = true)
    {
        $this->ignore = $ignore;
        return $this;
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
        $instance            = new self();
        $instance->operation = 'union';
        $instance->queries   = $queries;
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
     * Sets the values for an insert operation.
     *
     * @return $this
     */
    public function values(array $values)
    {
        if ($values === []) {
            return $this;
        }

        // Check if it's an array of arrays for bulk insert
        if (isset($values[0]) && is_array($values[0])) {
            // Bulk insert
            $this->insertValues = $values;
        } else {
            // Single row insert
            $this->insertValues[] = $values;
        }

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
     *                      The array key is the column name, and the value is
     *                      the value to assign to the column.
     *
     * @return $this
     */
    public function set($values)
    {
        if (empty($values)) {
            return $this;
        }

        foreach ($values as $field => $value) {
            $column = '`' . str_replace('`', '``', $field) . '`';
            if (is_string($value)) {
                $this->setClauses[]      = sprintf('%s = %%s', $column);
                $this->valuesToPrepare[] = $value;
            } elseif (is_numeric($value)) {
                $this->setClauses[]      = sprintf('%s = %%s', $column);
                $this->valuesToPrepare[] = $value;
            } elseif (is_null($value)) {
                $this->setClauses[] = $column . ' = NULL';
            }
        }

        return $this;
    }

    /**
     * Sets a raw value for a column, allowing for SQL expressions.
     *
     * @param string $column
     * @param string $expression
     * @param array $params
     * @return $this
     */
    public function setRaw($column, $expression, $params = [])
    {
        $this->setClauses[] = sprintf('`%s` = %s', str_replace('`', '``', $column), $expression);
        if (!empty($params)) {
            $this->valuesToPrepare = array_merge($this->valuesToPrepare, $params);
        }

        return $this;
    }

    /**
     * Add a WHERE clause to the query.
     *
     * @param string $field    The field to filter on.
     * @param string $operator The operator to use. Supported operators: =, !=, >, >=, <, <=, LIKE, NOT LIKE, IN, NOT IN, BETWEEN.
     * @param mixed  $value    The value to filter on. Can be a string, int, array or null.
     *
     * @return $this
     *
     * @throws InvalidArgumentException If the operator is not supported.
     */
    public function where($field, $operator, $value)
    {
        if ('BETWEEN' === strtoupper($operator) && is_array($value) && 2 === count($value) && (null !== $value[0] && null !== $value[1])) {
            $condition = $this->generateCondition($field, $operator, $value);
            if (!empty($condition)) {
                $this->whereClauses[]  = $condition['condition'];
                $this->valuesToPrepare = array_merge($this->valuesToPrepare, $condition['values']);
            }

            return $this;
        }

        if (is_array($value)) {
            $value = array_filter(array_values($value));
        }

        if (!is_numeric($value) && empty($value)) {
            return $this;
        }

        $condition = $this->generateCondition($field, $operator, $value);
        if (!empty($condition)) {
            $this->whereClauses[]  = $condition['condition'];
            $this->valuesToPrepare = array_merge($this->valuesToPrepare, $condition['values']);
        }

        return $this;
    }

    /**
     * Add a raw WHERE clause to the query. If values are provided, they will be
     * escaped and inserted into the query.
     *
     * @param string $condition The raw WHERE condition.
     * @param array  $values    Values to be inserted into the condition.
     *
     * @return $this
     */
    public function whereRaw($condition, $values = [])
    {
        $this->rawWhereClause[] = empty($values) ? $condition : $this->prepareQuery($condition, $values);

        return $this;
    }

    /**
     * Add a raw HAVING clause to the query. If values are provided, they will be
     * escaped and inserted into the clause. Any leading "HAVING" keyword is stripped
     * to ensure correct placement in the final query.
     *
     * @param string $condition The raw HAVING condition.
     * @param array  $values    Values to be inserted into the condition.
     *
     * @return $this
     */
    public function havingRaw($condition, $values = [])
    {
        // Strip an optional leading HAVING keyword to avoid duplication
        $condition = preg_replace('/^\s*HAVING\s+/i', '', $condition);
        $this->havingClauses[] = empty($values) ? $condition : $this->prepareQuery($condition, $values);

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
            $this->groupByClause = 'GROUP BY ' . $fields;
        }

        return $this;
    }

    /**
     * Sets the ORDER BY clause for the query.
     *
     * @param string|array $fields The fields to order by. Can be a comma-separated string or an array of fields.
     * @param string       $order  The order direction, either 'ASC' or 'DESC'. Defaults to 'DESC'.
     *
     * @return $this
     */
    public function orderBy($fields, $order = 'DESC')
    {
        if (empty($fields)) {
            return $this;
        }

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
                $orderParts[] = sprintf('%s %s', $field, $order);
            }

            $this->orderClause = 'ORDER BY ' . implode(', ', $orderParts);
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
     * @param int $page    The page number. Defaults to 1.
     * @param int $perPage The number of results to show per page. Defaults to 10.
     *
     * @return $this
     */
    public function perPage($page = 1, $perPage = 10)
    {
        $page    = intval($page);
        $perPage = intval($perPage);
        if ($page > 0 && $perPage > 0) {
            $offset            = ($page - 1) * $perPage;
            $this->limitClause = sprintf('LIMIT %d OFFSET %d', $perPage, $offset);
        }

        return $this;
    }

    /**
     * Join another table.
     *
     * @param string       $table      The table to join.
     * @param string|array $on         The join condition. Can be an array with two fields to join on, or a string with a condition.
     * @param array        $conditions An array of conditions to join on. Each condition is an array with three elements: field, operator, value.
     * @param string       $joinType   The type of join. Can be INNER, LEFT, or RIGHT. Defaults to INNER.
     *
     * @return $this
     *
     * @throws InvalidArgumentException If the join condition is invalid.
     */
    public function join($table, $on, $conditions = [], $joinType = 'INNER')
    {
        $joinType = strtoupper($joinType);
        if (is_array($on) && 2 == count($on)) {
            $joinClause = sprintf('%s JOIN %s ON %s = %s', $joinType, $table, $on[0], $on[1]);
            if (!empty($conditions)) {
                foreach ($conditions as $condition) {
                    $field    = $condition[0];
                    $operator = $condition[1];
                    $value    = $condition[2];
                    $cond     = $this->generateCondition($field, $operator, $value);
                    if (!empty($cond)) {
                        $joinClause .= ' AND ' . $cond['condition'];
                        $this->valuesToPrepare = array_merge($this->valuesToPrepare, $cond['values']);
                    }
                }
            }

            $this->joinClauses[] = $joinClause;
            return $this;
        }

        // Backward compatibility: allow two string fields passed separately
        if (is_string($on) && is_string($conditions) && '' !== $on && '' !== $conditions) {
            $this->joinClauses[] = sprintf('%s JOIN %s ON %s = %s', $joinType, $table, $on, $conditions);
            return $this;
        }

        // Allow raw ON condition string
        if (is_string($on) && '' !== $on && (empty($conditions) || (is_array($conditions) && empty($conditions)))) {
            $this->joinClauses[] = sprintf('%s JOIN %s ON %s', $joinType, $table, $on);
            return $this;
        }

        throw new InvalidArgumentException('Invalid join clause');
    }

    /**
     * Set the caching flag and expiration time.
     *
     * @param bool $flag       Whether to allow caching.
     * @param int  $expiration The cache expiration time in seconds.
     *
     * @return $this
     */
    public function allowCaching($flag = true, $expiration = 3600)
    {
        $this->allowCaching    = $flag;
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

    protected function getCacheKey($input)
    {
        $normalized = $input;
        if (preg_match('/BETWEEN\s+[\'\"]?(\d{4}-\d{2}-\d{2})[\s\d:]*[\'\"]?\s+AND\s+[\'\"]?(\d{4}-\d{2}-\d{2})[\s\d:]*[\'\"]?/i', $input, $matches)) {
            $from       = $matches[1];
            $to         = $matches[2];
            $normalized = preg_replace('/BETWEEN\s+[\'\"]?(\d{4}-\d{2}-\d{2})[\s\d:]*[\'\"]?\s+AND\s+[\'\"]?(\d{4}-\d{2}-\d{2})[\s\d:]*[\'\"]?/i', sprintf("BETWEEN '%s' AND '%s'", $from, $to), $input);
        }

        $normalized = preg_replace_callback('/(\d{4}-\d{2}-\d{2})[\s\d:]{0,8}/', fn ($m) => $m[1], $normalized);
        $hash       = substr(md5($normalized), 0, 10);
        return sprintf('wp_slimstat_cache_%s', $hash);
    }

    protected function getCachedResult($input)
    {
        $cacheKey = $this->getCacheKey($input);
        return get_transient($cacheKey);
    }

    protected function setCachedResult($input, $result, $expiration = DAY_IN_SECONDS)
    {
        $cacheKey = $this->getCacheKey($input);
        return set_transient($cacheKey, $result, $expiration);
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
     *                              - boolean: whether a split range was found
     *                              - int|null: historical start timestamp
     *                              - int|null: historical end timestamp (inclusive)
     *                              - int|null: live start timestamp (inclusive)
     *                              - int|null: live end timestamp
     */
    protected function getSplitDateRanges2()
    {
        $dtField    = 'dt';
        $todayStart = $this->getTodayDate();
        time();
        foreach ($this->whereClauses as $idx => $clause) {
            if (preg_match('/' . $dtField . ' BETWEEN %s AND %s/', $clause)) {
                $from  = null;
                $to    = null;
                $dtIdx = 0;
                foreach ($this->whereClauses as $i => $c) {
                    if ($i == $idx) {
                        break;
                    }

                    if (preg_match('/%s/', $c)) {
                        $dtIdx += substr_count($c, '%s');
                    }
                }

                $from   = $this->valuesToPrepare[$dtIdx] ?? null;
                $to     = $this->valuesToPrepare[$dtIdx + 1] ?? null;
                $fromTs = is_numeric($from) ? intval($from) : strtotime($from);
                $toTs   = is_numeric($to) ? intval($to) : strtotime($to);
                if (null !== $fromTs && null !== $toTs && $fromTs < $todayStart && $toTs >= $todayStart) {
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
     * @param string $field    Field name
     * @param string $operator SQL operator
     * @param mixed  $value    Value to be used in the condition. Can be a string, int, array or null.
     *
     * @return array|false Array with keys 'condition' and 'values', or false if the condition could not be generated.
     *
     * @throws InvalidArgumentException If the operator is not supported.
     */
    protected function generateCondition($field, $operator, $value)
    {
        $condition = '';
        $values    = [];
        switch ($operator) {
            case '=':
            case '!=':
            case '>':
            case '>=':
            case '<':
            case '<=':
            case 'LIKE':
            case 'NOT LIKE':
                $condition = sprintf('%s %s %%s', $field, $operator);
                $values[]  = $value;
                break;
            case 'IS':
            case 'IS NOT':
                if (is_null($value)) {
                    $condition = sprintf('%s %s NULL', $field, $operator);
                }

                break;
            case 'IN':
            case 'NOT IN':
                if (is_string($value)) {
                    $value = explode(',', $value);
                }

                if (!empty($value) && is_array($value) && 1 == count($value)) {
                    $operator = ('IN' === $operator) ? '=' : '!=';
                    return $this->generateCondition($field, $operator, reset($value));
                }

                if (!empty($value) && is_array($value)) {
                    $placeholders = implode(', ', array_fill(0, count($value), '%s'));
                    $condition    = sprintf('%s %s (%s)', $field, $operator, $placeholders);
                    $values       = $value;
                }

                break;
            case 'BETWEEN':
                if (is_array($value) && 2 === count($value)) {
                    $condition = sprintf('%s BETWEEN %%s AND %%s', $field);
                    $values    = $value;
                }

                break;
            default:
                throw new InvalidArgumentException('Unsupported operator: ' . $operator);
        }

        if ('' === $condition || '0' === $condition) {
            return null;
        }

        return [
            'condition' => $condition,
            'values'    => $values,
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
     *
     * @throws InvalidArgumentException If the operation type is unknown.
     */
    protected function buildQuery()
    {
        switch ($this->operation) {
            case 'select':
                $query = sprintf('SELECT %s FROM %s', $this->fields, $this->table);
                break;
            case 'update':
                $operation = $this->ignore ? 'UPDATE IGNORE' : 'UPDATE';
                $query = sprintf('%s %s SET ', $operation, $this->table) . implode(', ', $this->setClauses);
                break;
            case 'delete':
                $query = 'DELETE FROM ' . $this->table;
                break;
            case 'insert':
                if (empty($this->insertValues)) {
                    return '';
                }

                $operation = $this->ignore ? 'INSERT IGNORE INTO' : 'INSERT INTO';
                $sampleRow = $this->insertValues[0];
                $keys      = array_keys($sampleRow);
                $query     = sprintf('%s %s (`%s`) VALUES ', $operation, $this->table, implode('`, `', $keys));

                $valueSets = [];
                foreach ($this->insertValues as $row) {
                    $placeholders  = implode(', ', array_fill(0, count($row), '%s'));
                    $valueSets[]   = '(' . $placeholders . ')';
                    foreach ($row as $value) {
                        $this->valuesToPrepare[] = $value;
                    }
                }

                $query .= implode(', ', $valueSets);
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
            $query .= ' WHERE ' . implode(sprintf(' %s ', $this->whereRelation), $this->whereClauses);
        }

        if (!empty($this->rawWhereClause)) {
            if (!empty($this->whereClauses)) {
                $query .= ' AND ' . implode(' AND ', $this->rawWhereClause);
            } else {
                $query .= ' WHERE ' . implode(' AND ', $this->rawWhereClause);
            }
        }

        if (!empty($this->groupByClause)) {
            $query .= ' ' . $this->groupByClause;
        }

        if (!empty($this->havingClauses)) {
            $query .= ' HAVING ' . implode(' AND ', $this->havingClauses);
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
     *
     * @param string $query
     * @param array  $args
     *
     * @return string The prepared query
     */
    protected function prepareQuery($query, $args = [])
    {
        if (preg_match('/%[i|s|f|d]/', $query)) {
            $placeholder_count = preg_match_all('/%[i|s|f|d]/', $query, $matches);
            $args_count        = is_array($args) ? count($args) : (empty($args) ? 0 : 1);
            if (1 === $placeholder_count) {
                $query = is_array($args) ? $this->db->prepare($query, reset($args)) : $this->db->prepare($query, $args);
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
     * @param array  $args  The query arguments.
     *
     * @return string The generated cache key.
     */
    protected function getCacheKeyForQuery($query, $args = [])
    {
        $data = [
            'query' => $query,
            'args'  => $args,
        ];
        $hash = substr(md5(serialize($data)), 0, 16);
        return 'wp_slimstat_query_' . $hash;
    }

    /**
     * Retrieves the cached result for the given query and args
     *
     * @param string $query The SQL query
     * @param array  $args  The query arguments
     *
     * @return mixed The query result, or false if there is no cached result
     */
    protected function getCachedResultForQuery($query, $args = [])
    {
        $cacheKey = $this->getCacheKeyForQuery($query, $args);
        $data     = get_transient($cacheKey);
        if (false === $data) {
            return false;
        }

        if (is_array($data) && isset($data['chunks']) && isset($data['size'])) {
            $chunks = [];
            for ($i = 0; $i < $data['chunks']; $i++) {
                $chunk = get_transient($cacheKey . '_' . $i);
                if (false === $chunk) {
                    return false;
                }

                $chunks[] = $chunk;
            }

            $data = implode('', $chunks);
        } elseif (is_array($data)) {
            $data = serialize($data);
        }

        if (function_exists('gzuncompress') && is_string($data)) {
            $first2 = substr($data, 0, 2);
            if ("\x1f\x8b" === $first2 || "\x78\x9c" === $first2 || "\x78\xda" === $first2) {
                $data = @gzuncompress($data);
            }
        }

        return @unserialize($data);
    }

    /**
     * Sets the transient cache for the given query and args
     *
     * @param string $query      The SQL query
     * @param array  $args       The query arguments
     * @param mixed  $result     The query result
     * @param int    $expiration The cache expiration time, in seconds
     *
     * @return bool True if cache was successfully set, false otherwise
     */
    protected function setCachedResultForQuery($query, $args, $result, $expiration = 300)
    {
        $cacheKey = $this->getCacheKeyForQuery($query, $args);
        $data     = serialize($result);

        $max_chunk_size = 900 * 1024; // 900KB
        $old_meta       = get_transient($cacheKey);
        if (is_array($old_meta) && isset($old_meta['chunks'])) {
            for ($i = 0; $i < $old_meta['chunks']; $i++) {
                delete_transient($cacheKey . '_' . $i);
            }
        }

        if (strlen($data) > $max_chunk_size) {
            $chunks = str_split($data, $max_chunk_size);
            $meta   = [
                'chunks' => count($chunks),
                'size'   => strlen($data),
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
     *
     * @return array<int, int, int, int, int, int, int>
     */
    protected function getSplitDateRanges()
    {
        $dtField    = 'dt';
        $todayStart = $this->getTodayDate();
        foreach ($this->whereClauses as $idx => $clause) {
            if (preg_match('/' . $dtField . ' BETWEEN %s AND %s/', $clause)) {
                $dtIdx = 0;
                foreach ($this->whereClauses as $i => $c) {
                    if ($i == $idx) {
                        break;
                    }

                    if (preg_match('/%s/', $c)) {
                        $dtIdx += substr_count($c, '%s');
                    }
                }

                $from   = $this->valuesToPrepare[$dtIdx] ?? null;
                $to     = $this->valuesToPrepare[$dtIdx + 1] ?? null;
                $fromTs = is_numeric($from) ? intval($from) : strtotime($from);
                $toTs   = is_numeric($to) ? intval($to) : strtotime($to);
                if (null !== $fromTs && null !== $toTs && $fromTs < $todayStart && $toTs >= $todayStart) {
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
     * @param array  $historical The result rows from the historical part of the query
     * @param array  $live       The result rows from the live part of the query
     * @param string $groupKey   The key to group the results by
     * @param array  $sumFields  The fields to sum for each group
     *
     * @return array The merged and grouped result rows
     */
    protected function mergeGroupResults($historical, $live, $groupKey = null, $sumFields = ['counthits'])
    {
        $historical = is_array($historical) ? $historical : [];
        $live       = is_array($live) ? $live : [];

        // If no group key provided, try to determine it from the data
        if (!$groupKey) {
            // Try to find a suitable group key from the first row
            $firstRow = !empty($historical) ? $historical[0] : (!empty($live) ? $live[0] : null);
            if ($firstRow && is_array($firstRow)) {
                // Use the first column that's not a sum field
                foreach (array_keys($firstRow) as $key) {
                    if (!in_array($key, $sumFields)) {
                        $groupKey = $key;
                        break;
                    }
                }
            }

            // If still no group key, just merge without grouping
            if (!$groupKey) {
                return array_merge($historical, $live);
            }
        }

        $result = [];
        foreach ($historical as $row) {
            if (isset($row[$groupKey])) {
                $key          = $row[$groupKey];
                $result[$key] = $row;
            }
        }

        foreach ($live as $row) {
            if (isset($row[$groupKey])) {
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
        }

        return array_values($result);
    }

    /**
     * Helper: Extract date ranges from WHERE clauses
     *
     * @return array Array of extracted date ranges with keys from, to, clauseIdx, and valueIdx
     */
    protected function extractDateRangesFromWhere()
    {
        $dtField = 'dt';
        $ranges  = [];
        $dtIdx   = 0;
        foreach ($this->whereClauses as $idx => $clause) {
            if (preg_match('/' . $dtField . ' BETWEEN %s AND %s/', $clause)) {
                $from     = $this->valuesToPrepare[$dtIdx] ?? null;
                $to       = $this->valuesToPrepare[$dtIdx + 1] ?? null;
                $ranges[] = [
                    'from'      => $from,
                    'to'        => $to,
                    'clauseIdx' => $idx,
                    'valueIdx'  => $dtIdx,
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
     *
     * @param int|string $from                Start date (Y-m-d or Y-m-d H:i:s or timestamp)
     * @param int|string $to                  End date (Y-m-d or Y-m-d H:i:s or timestamp)
     * @param array      $baseWhereClauses    where clauses to use for the query
     * @param array      $baseValuesToPrepare values to prepare for the query
     *
     * @return array result set
     */
    protected function processDateRange($from, $to, $baseWhereClauses, $baseValuesToPrepare)
    {
        $todayStart = $this->getTodayDate();
        $fromTs     = is_numeric($from) ? intval($from) : strtotime($from);
        $toTs       = is_numeric($to) ? intval($to) : strtotime($to);

        if ($fromTs >= $todayStart) {
            $liveQuery                  = clone $this;
            $liveQuery->whereClauses    = $baseWhereClauses;
            $liveQuery->valuesToPrepare = $baseValuesToPrepare;
            $liveQuery->whereDate('dt', ['from' => $fromTs, 'to' => $toTs], true);
            $liveQuery->allowCaching(false, 0);
            return $liveQuery->getAll();
        }

        if ($toTs < $todayStart) {
            $cacheQuery                  = clone $this;
            $cacheQuery->whereClauses    = $baseWhereClauses;
            $cacheQuery->valuesToPrepare = $baseValuesToPrepare;
            $cacheQuery->whereDate('dt', ['from' => $fromTs, 'to' => $toTs]);
            $cacheQuery->allowCaching(true, $this->cacheExpiration);
            return $cacheQuery->getAll();
        }

        $histQuery                  = clone $this;
        $histQuery->whereClauses    = $baseWhereClauses;
        $histQuery->valuesToPrepare = $baseValuesToPrepare;
        $histQuery->whereDate('dt', ['from' => $fromTs, 'to' => $todayStart - 1]);
        $histQuery->allowCaching(true, $this->cacheExpiration);

        $historical = $histQuery->getAll();

        $liveQuery                  = clone $this;
        $liveQuery->whereClauses    = $baseWhereClauses;
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
            if (false !== $cachedResult) {
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
            if (false !== $cachedResult) {
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
            if (false !== $cachedResult) {
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
     *
     * @param string      $field
     * @param string|null $operator
     *
     * @return bool
     */
    public function hasWhereClause($field, $operator = null)
    {
        foreach ($this->whereClauses as $clause) {
            if ($operator) {
                if (false !== stripos($clause, sprintf('%s %s', $field, $operator))) {
                    return true;
                }
            } elseif (false !== stripos($clause, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a date range condition and enable cache if possible.
     *
     * @param string       $field
     * @param array|string $date
     *
     * @return $this
     */
    public function whereDate($field, $date, $isLiveQuery = false)
    {
        if (empty($date)) {
            return $this;
        }

        if (is_array($date)) {
            $from = $date['from'] ?? '';
            $to   = $date['to'] ?? '';
        } elseif (is_string($date)) {
            $from = $date;
            $to   = $date;
        } else {
            return $this;
        }

        if ('dt' === $field) {
            if (!empty($from) && !empty($to)) {
                $fromTs = is_numeric($from) ? intval($from) : strtotime($from);
                $toTs   = is_numeric($to) ? intval($to) : strtotime($to);

                $this->whereClauses[]    = sprintf('%s BETWEEN %%s AND %%s', $field);
                $this->valuesToPrepare[] = $fromTs;
                $this->valuesToPrepare[] = $toTs;
                $this->canUseCacheForDateRange($toTs);
                if ($isLiveQuery) {
                    $this->_isLiveQuery = true;
                }
            }
        } elseif (!empty($from) && !empty($to)) {
            if (10 === strlen($from)) {
                $from .= ' 00:00:00';
            }

            if (10 === strlen($to)) {
                $to .= ' 23:59:59';
            }

            $this->whereClauses[]    = sprintf('%s BETWEEN %%s AND %%s', $field);
            $this->valuesToPrepare[] = $from;
            $this->valuesToPrepare[] = $to;
            $this->canUseCacheForDateRange($to);
            if ($isLiveQuery) {
                $this->_isLiveQuery = true;
            }
        }

        return $this;
    }

    /**
     * Executes a query for operations like INSERT, UPDATE, DELETE.
     *
     * @return int|bool Number of affected rows, or false on error. For INSERT, returns the insert ID.
     * @throws \Exception
     */
    public function execute()
    {
        if ('select' === $this->operation) {
            throw new \Exception('execute() cannot be used for SELECT queries. Use getAll(), getVar(), getRow(), or getCol().');
        }

        $query = $this->buildQuery();

        if (empty($query)) {
            return false;
        }

        $prepared_query = $this->prepareQuery($query, $this->valuesToPrepare);

        $result = $this->db->query($prepared_query);

        if ('insert' === $this->operation) {
            return $this->db->insert_id ?: $result;
        }

        return $result;
    }

    /**
     * Get all results from a query.
     * If this is a live query (i.e. the query has a live date range), this function will
     * split the query into two parts: a historical part that can be safely cached, and a live
     * part that should not be cached.
     * If this is not a live query, the function will simply return the result of the query.
     *
     * @return array The result of the query
     */
    public function getAll()
    {
        if (null !== $this->_isLiveQuery && $this->_isLiveQuery) {
            $query = $this->buildQuery();
            $query = $this->prepareQuery($query, $this->valuesToPrepare);
            return $this->db->get_results($query, ARRAY_A);
        }

        $ranges = $this->extractDateRangesFromWhere();
        if (count($ranges) > 1) {
            $results = [];
            foreach ($ranges as $range) {
                if (empty($range['from']) || empty($range['to'])) {
                    continue;
                }

                $baseWhereClauses    = $this->whereClauses;
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

        [$split, $histFrom, $histTo, $liveFrom, $liveTo, $dtIdx, $dtClauseIdx] = $this->getSplitDateRanges();
        if ($split) {
            $baseWhereClauses    = $this->whereClauses;
            $baseValuesToPrepare = $this->valuesToPrepare;
            array_splice($baseWhereClauses, $dtClauseIdx, 1);
            $baseValues = $baseValuesToPrepare;
            array_splice($baseValues, $dtIdx, 2);

            // Clone for historical
            $histQuery                  = clone $this;
            $histQuery->whereClauses    = $baseWhereClauses;
            $histQuery->valuesToPrepare = $baseValues;
            $histQuery->whereDate('dt', ['from' => $histFrom, 'to' => $histTo]);
            $histQuery->allowCaching(true, $this->cacheExpiration);
            try {
                $historical = $histQuery->getAll();
            } catch (Exception $e) {
                $historical = [];
            }

            // Clone for live
            $liveQuery                  = clone $this;
            $liveQuery->whereClauses    = $baseWhereClauses;
            $liveQuery->valuesToPrepare = $baseValues;
            $liveQuery->whereDate('dt', ['from' => $liveFrom, 'to' => $liveTo], true);
            $liveQuery->allowCaching(false, 0);
            try {
                $live = $liveQuery->getAll();
            } catch (Exception $e) {
                $live = [];
            }

            if (is_array($live)) {
                $dtList = array_map(fn ($row) => $row['dt'] ?? null, $live);
            }

            // Let mergeGroupResults determine the group key automatically
            // This handles complex GROUP BY expressions and aliases properly
            $merged = $this->mergeGroupResults($live, $historical);
            if (is_array($merged)) {
                $dtList = array_map(fn ($row) => $row['dt'] ?? null, $merged);
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

            if (false !== $cachedResult) {
                return $cachedResult;
            }
        }

        try {
            $result = $this->db->get_results($query, ARRAY_A);
        } catch (Exception $exception) {
            $result = [];
        }

        if ($this->allowCaching) {
            try {
                $this->setCachedResultForQuery($query, $this->valuesToPrepare, $result, $this->cacheExpiration);
            } catch (Exception $exception) {
                // ignore
            }
        }

        return $result;
    }
}
