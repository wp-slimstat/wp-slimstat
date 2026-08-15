<?php

namespace SlimStat\Utils;

use InvalidArgumentException;

class Query
{
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

    private $setValuesToPrepare = [];

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

    private static $processingTimestamp = null;

    /**
     * Constructor.
     *
     * Initializes the query with the global $wpdb instance.
     */
    public function __construct()
    {
        $this->db = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
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
     * Run this query on a SPECIFIC connection instead of the analytics one.
     *
     * The constructor binds `\wp_slimstat::$wpdb` — the analytics handle, which under the
     * custom-DB add-on is a DIFFERENT database, possibly a different server. That is
     * correct for `slim_` tables and WRONG for core tables: a `COUNT(*)` on `wp_posts`
     * issued on the analytics connection hits a database that has no `wp_posts` (F6/C44).
     * `get_your_blog()` did exactly that for six of its seven metrics, so on every
     * external-DB install its post/page/comment counts read zero or errored silently.
     *
     * @param \wpdb $db The connection to run on.
     * @return $this
     */
    public function on(\wpdb $db)
    {
        $this->db = $db;
        return $this;
    }

    /**
     * Run this query on the WORDPRESS (core) connection — where the core tables live.
     *
     * A named shorthand for `->on($GLOBALS['wpdb'])`, because "this queries a core table,
     * not an analytics one" is the decision a reader needs to see, not the handle plumbing.
     * Expressed as delegation, not a parallel assignment, so `on()` is the one place that
     * binds a handle and is exercised on every `local()` call.
     *
     * @return $this
     */
    public function local()
    {
        return $this->on($GLOBALS['wpdb']);
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
                $this->setClauses[]           = sprintf('%s = %%s', $column);
                $this->setValuesToPrepare[]   = $value;
            } elseif (is_numeric($value)) {
                $this->setClauses[]           = sprintf('%s = %%s', $column);
                $this->setValuesToPrepare[]   = $value;
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
            $this->setValuesToPrepare = array_merge($this->setValuesToPrepare, $params);
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
     * Sets the LIMIT clause for the query, with an optional OFFSET.
     *
     * @param int $limit  The maximum number of results to return.
     * @param int $offset The number of rows to skip. Defaults to 0.
     *
     * @return $this
     */
    public function limit($limit, $offset = 0)
    {
        $limit  = intval($limit);
        $offset = intval($offset);
        if ($offset > 0) {
            $this->limitClause = sprintf('LIMIT %d OFFSET %d', $limit, $offset);
        } else {
            $this->limitClause = 'LIMIT ' . $limit;
        }
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
     * @param array|string $conditions Extra conditions to AND into the join, each a
     *                                 [field, operator, value] triple. For backward
     *                                 compatibility this may instead be a STRING: the
     *                                 right-hand field of a two-string `$on = $conditions`
     *                                 join. Both report queries in
     *                                 admin/view/wp-slimstat-db.php use the string form,
     *                                 so the branch at the bottom of this method is live —
     *                                 do not "simplify" it away as unreachable.
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
     * Set the processing timestamp context for caching decisions.
     * This should be set to the timestamp of the event being processed (e.g., $stat['dt'])
     * to ensure caching decisions are based on the event time, not the current server time.
     *
     * @param int|null $timestamp Unix timestamp of the event being processed, or null to use current time.
     * @return void
     */
    public static function setProcessingTimestamp($timestamp)
    {
        self::$processingTimestamp = $timestamp;
    }

    /**
     * Get the timestamp for the start of today.
     * If a processing timestamp has been set, calculates "today" based on that timestamp.
     * Otherwise, uses the current server time.
     *
     * @return int The timestamp for the start of today (midnight).
     */
    protected function getTodayDate()
    {
        if (null !== self::$processingTimestamp) {
            return strtotime(date('Y-m-d 00:00:00', self::$processingTimestamp));
        }
        return strtotime(date('Y-m-d 00:00:00'));
    }

    // Removed: getCacheKey() / getCachedResult() / setCachedResult().
    //
    // A second, unused caching mechanism keyed on `wp_slimstat_cache_<hash>`, with a
    // date-normalising step that would have collapsed a window's time-of-day into its date.
    // Nothing in free or Pro ever called it — every live cache path goes through the
    // *ForQuery() variants, which key on `wp_slimstat_query_<hash>`.
    //
    // It was not harmless. The upgrade routine's stale-cache sweep was written against THIS
    // prefix, so it matched zero rows on every upgrade while the real cache accumulated
    // untouched: measured at 0 matched against 2,146 present. Deleting the dead half is
    // what makes the surviving prefix unambiguous.

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
            $wrappedClauses = array_map(fn($clause) => "($clause)", $this->rawWhereClause);
            if (!empty($this->whereClauses)) {
                $query .= ' AND ' . implode(' AND ', $wrappedClauses);
            } else {
                $query .= ' WHERE ' . implode(' AND ', $wrappedClauses);
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
            // Data is already an array (from transient), return directly
            return $data;
        }

        if (function_exists('gzuncompress') && is_string($data)) {
            $first2 = substr($data, 0, 2);
            if ("\x1f\x8b" === $first2 || "\x78\x9c" === $first2 || "\x78\xda" === $first2) {
                $data = @gzuncompress($data);
            }
        }

        // Use JSON decode instead of unserialize for security
        $decoded = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Return false if JSON decode failed (corrupted or legacy data)
        return false;
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
        // Use JSON encode instead of serialize for security
        $data     = wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (false === $data) {
            return false;
        }

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
            if (strlen(wp_json_encode($meta)) > $max_chunk_size) {
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

        // Rows are identified by EVERY non-aggregate column they carry, not by one guessed
        // column. The old merge keyed on "the first column that isn't counthits", so a
        // report grouping on (browser, browser_version) was re-keyed on browser alone and
        // every version collapsed onto whichever one the live half listed first. (D5)
        //
        // The key is read from the ROW rather than parsed out of the GROUP BY clause, and
        // that is the safety property. In a grouped result set every non-aggregate column
        // is functionally dependent on the group key, so keying on all of them is always at
        // least as fine-grained as the real grouping and can never fuse two groups that
        // belong apart. Parsing the GROUP BY instead would have to survive qualified names,
        // backticks, aliases and expressions containing commas — and each of those fails on
        // the wrong side: a partly-wrong key silently fabricates rows, where an
        // over-specific key merely leaves one unmerged, which is visible.
        $rules   = $this->aggregateRules($sumFields);
        $keyCols = null !== $groupKey ? (array) $groupKey : null;

        $keyOf = static function (array $row) use ($rules, $keyCols) {
            $parts = [];
            foreach ($row as $col => $value) {
                $isKey = null !== $keyCols ? in_array($col, $keyCols, true) : !isset($rules[$col]);
                if (!$isKey) {
                    continue;
                }
                // NULL and '' are different groups in SQL and must not collapse into one.
                // The previous merge went further and dropped NULL-keyed groups entirely,
                // because isset() is false for null — one country group, 175 rows, on the
                // reference dataset.
                $parts[] = null === $value ? "\0NULL" : (string) $value;
            }

            return [] === $parts ? null : implode("\0", $parts);
        };

        $result = [];
        foreach ([$historical, $live] as $partition) {
            foreach ($partition as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $key = $keyOf($row);
                if (null === $key) {
                    // Every column is an aggregate, so nothing identifies this row. Keep it
                    // rather than throw it away.
                    $result[] = $row;
                    continue;
                }

                if (!isset($result[$key])) {
                    $result[$key] = $row;
                    continue;
                }

                foreach ($row as $col => $value) {
                    $result[$key][$col] = self::combineValue(
                        isset($rules[$col]) ? $rules[$col] : null,
                        array_key_exists($col, $result[$key]) ? $result[$key][$col] : null,
                        $value
                    );
                }
            }
        }

        return array_values($result);
    }

    /**
     * Which columns identify a row, and how to combine the rest, for a split query.
     *
     * Derived from the SELECT list and GROUP BY, both of which are still intact and
     * unparsed at merge time. An aggregate can only be merged from partition results when
     * its per-partition output is a sufficient statistic: COUNT and SUM add, MAX and MIN
     * take the extremum, GROUP_CONCAT unions. AVG needs the per-partition counts to weight
     * it and COUNT(DISTINCT) is not recoverable at all — neither reaches this path today,
     * and both keep the historical behaviour of taking the first value seen rather than
     * inventing a number.
     *
     * @param string[] $sumFields Columns the caller declares additive whatever the SQL says.
     * @return array<string, array{0: string, 1: string|null}> alias => [operation, argument]
     */
    private function aggregateRules(array $sumFields)
    {
        $rules = [];

        foreach ($this->splitTopLevelCommas((string) $this->fields) as $expr) {
            $alias = $expr;
            if (preg_match('/^(.*?)\s+AS\s+([`\'"]?)([A-Za-z0-9_]+)\2\s*$/is', $expr, $m)) {
                $alias = $m[3];
                $expr  = trim($m[1]);
            }
            $alias = trim($alias, " `'\"");

            // COUNT(DISTINCT x) wears COUNT's clothes but is not additive: a value present
            // in both halves would be counted once per half.
            if (preg_match('/^\s*COUNT\s*\(\s*DISTINCT/i', $expr)) {
                $rules[$alias] = ['FIRST', null];
                continue;
            }

            if (preg_match('/^\s*(COUNT|SUM|MAX|MIN|AVG|GROUP_CONCAT)\s*\(/i', $expr, $fn)) {
                $op  = strtoupper($fn[1]);
                $sep = null;
                if ('GROUP_CONCAT' === $op) {
                    $sep = preg_match('/SEPARATOR\s+([\'"])(.*?)\1/is', $expr, $m) ? $m[2] : ',';
                }
                $rules[$alias] = [$op, $sep];
            }
        }

        foreach ($sumFields as $field) {
            $rules[$field] = ['SUM', null];
        }

        return $rules;
    }

    /**
     * Combine one column's value across the two halves of a split range.
     *
     * @param array{0: string, 1: string|null}|null $rule
     * @param mixed                                 $a
     * @param mixed                                 $b
     * @return mixed
     */
    private static function combineValue($rule, $a, $b)
    {
        if (null === $rule) {
            // Part of the group key: both halves carry the same value by construction.
            return $a;
        }

        list($op, $arg) = $rule;

        switch ($op) {
            case 'COUNT':
            case 'SUM':
                return $a + $b;
            case 'MAX':
                return (null === $a || (null !== $b && $b > $a)) ? $b : $a;
            case 'MIN':
                return (null === $a || (null !== $b && $b < $a)) ? $b : $a;
            case 'GROUP_CONCAT':
                $parts = array_merge(
                    '' === (string) $a ? [] : explode($arg, (string) $a),
                    '' === (string) $b ? [] : explode($arg, (string) $b)
                );
                return implode($arg, array_unique(array_filter($parts, static function ($v) {
                    return '' !== $v;
                })));
            default:
                // AVG, COUNT(DISTINCT) and anything unrecognised: keep what we have rather
                // than fabricate a value the two halves cannot support.
                return $a;
        }
    }

    /**
     * Split a clause on its top-level commas, ignoring those nested in parentheses.
     *
     * Parentheses only: a comma or bracket inside a string literal would confuse the depth
     * counter. No clause this plugin generates contains one.
     *
     * @param string $fields
     * @return string[]
     */
    private function splitTopLevelCommas($fields)
    {
        $out   = [];
        $depth = 0;
        $buf   = '';

        for ($i = 0, $len = strlen($fields); $i < $len; $i++) {
            $c = $fields[$i];
            if ('(' === $c) {
                $depth++;
            } elseif (')' === $c) {
                $depth--;
            }

            if (',' === $c && 0 === $depth) {
                $out[] = $buf;
                $buf   = '';
                continue;
            }

            $buf .= $c;
        }

        $out[] = $buf;

        return array_values(array_filter(array_map('trim', $out), static function ($v) {
            return '' !== $v;
        }));
    }

    /**
     * Re-sort merged results to honour the original ORDER BY clause.
     *
     * After mergeGroupResults() sums counts from historical + live partitions,
     * the relative ordering may no longer match the SQL ORDER BY (e.g. a row
     * whose counthits grew after summing should move up).  This method parses
     * the stored orderClause and applies an equivalent PHP usort().
     *
     * Only fields that exist as keys in the result rows are used for sorting;
     * SQL expressions (MAX(dt), REPLACE(…), etc.) are silently skipped because
     * they don't appear as array keys after $wpdb->get_results().
     *
     * @param array $results Merged result rows (associative arrays).
     *
     * @return array Re-sorted rows.
     */
    protected function sortMergedResults(array $results): array
    {
        if (empty($this->orderClause) || empty($results)) {
            return $results;
        }

        // Strip "ORDER BY " prefix → "counthits DESC, resource ASC"
        $orderStr = preg_replace('/^ORDER\s+BY\s+/i', '', $this->orderClause);
        $parts    = array_map('trim', explode(',', $orderStr));

        // Determine which fields actually exist in the result rows.
        $availableKeys = array_keys($results[0]);

        $sortFields = [];
        foreach ($parts as $part) {
            if (preg_match('/^(.+?)\s+(ASC|DESC)$/i', $part, $m)) {
                $field = $m[1];
                $dir   = strtoupper($m[2]);

                // Resolve SQL aggregate functions to their result-set alias.
                // e.g. MAX(dt) → dt (from "MAX(dt) AS dt" in the SELECT).
                $resolvedField = $field;
                if (!in_array($field, $availableKeys, true) && preg_match('/^(?:MAX|MIN|COUNT|SUM|AVG)\((\w+)\)$/i', $field, $aggMatch)) {
                    $resolvedField = $aggMatch[1];
                }

                if (in_array($resolvedField, $availableKeys, true)) {
                    $sortFields[] = ['field' => $resolvedField, 'dir' => $dir];
                }
            }
        }

        if (empty($sortFields)) {
            return $results;
        }

        usort($results, static function ($a, $b) use ($sortFields) {
            foreach ($sortFields as $sf) {
                $field = $sf['field'];
                $valA  = $a[$field] ?? null;
                $valB  = $b[$field] ?? null;

                // NULLs sort last regardless of direction.
                if (null === $valA && null === $valB) {
                    continue;
                }
                if (null === $valA) {
                    return 1;
                }
                if (null === $valB) {
                    return -1;
                }

                if (is_numeric($valA) && is_numeric($valB)) {
                    $cmp = ((float) $valA <=> (float) $valB);
                } else {
                    $cmp = strcmp((string) $valA, (string) $valB);
                }

                if (0 !== $cmp) {
                    return ('DESC' === $sf['dir']) ? -$cmp : $cmp;
                }
            }

            return 0;
        });

        return $results;
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
     * Whether the query matches at least one row.
     *
     * Bounded to a single row, so the cost does not grow with the number of matches.
     * Given its own terminal because the shape people reach for instead is
     * `COUNT(...) > 0`, which has to visit every match before it can answer — the
     * defect this exists to make hard to reintroduce. (D43)
     *
     * @return bool
     */
    public function exists(): bool
    {
        return null !== $this->limit(1)->getVar();
    }

    /**
     * Execute the query and return a single value from the first row
     *
     * This is a shortcut for `getAll()[0][0]`
     *
     * @return mixed The value, or false/null if no rows are returned
     */
    /**
     * Execute the query and return a single scalar.
     *
     * @param string $networkAggregate The OUTER aggregate that recombines a network-wide UNION,
     *                                 from NetworkMerge::outerAggregate(). Empty — the default —
     *                                 means this query is not eligible for merging and stays on
     *                                 the current blog. D22 / M1.
     */
    public function getVar(string $networkAggregate = '')
    {
        // When caching is enabled and the date range includes today, skip cache
        // to stay consistent with getAll() which always fetches fresh live data.
        // Without this, cached scalar values (e.g. $pageviews) become stale while
        // getAll() returns fresh grouped data, causing percentage calculations >100%.
        $useCache = $this->allowCaching;
        if ($useCache) {
            [$split] = $this->getSplitDateRanges();
            if ($split) {
                $useCache = false;
            }
        }

        $query = $this->buildQuery();
        $query = $this->prepareQuery($query, $this->valuesToPrepare);

        // D22 — where the network scoping happens, and it happens HERE because here is where the
        // SQL is finished. `admin/view/wp-slimstat-db.php` applied this filter around its legacy
        // string-SQL path only, so every report that had been migrated to this builder silently
        // left the Network View behind: get_top, get_recent, get_group_by, the charts, goals and
        // funnels. Scoping the denominator without them is the ~2.7x understatement PITFALLS 23
        // records.
        //
        // The caller's aggregate is what makes this safe. Without one the filter is not applied
        // at all, so a query nobody has declared a merge for cannot be silently summed — and a
        // silent SUM over COUNT(DISTINCT ip) is exactly the 7-where-the-answer-is-6 defect M1
        // was ratified to prevent.
        if ('' !== $networkAggregate) {
            $query    = (string) apply_filters('slimstat_get_var_sql', $query, $networkAggregate);
            $useCache = false; // the union spans blogs; the per-blog cache key does not.
        }

        if ($useCache) {
            $cachedResult = $this->getCachedResultForQuery($query, $this->valuesToPrepare);
            if (false !== $cachedResult) {
                return $cachedResult;
            }
        }

        $result = $this->db->get_var($query);
        if ($useCache) {
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
        $useCache = $this->allowCaching;
        if ($useCache) {
            [$split] = $this->getSplitDateRanges();
            if ($split) {
                $useCache = false;
            }
        }

        $query = $this->buildQuery();
        $query = $this->prepareQuery($query, $this->valuesToPrepare);
        if ($useCache) {
            $cachedResult = $this->getCachedResultForQuery($query, $this->valuesToPrepare);
            if (false !== $cachedResult) {
                return $cachedResult;
            }
        }

        $result = $this->db->get_row($query);
        if ($useCache) {
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
        $useCache = $this->allowCaching;
        if ($useCache) {
            [$split] = $this->getSplitDateRanges();
            if ($split) {
                $useCache = false;
            }
        }

        $query = $this->buildQuery();
        $query = $this->prepareQuery($query, $this->valuesToPrepare);
        if ($useCache) {
            $cachedResult = $this->getCachedResultForQuery($query, $this->valuesToPrepare);
            if (false !== $cachedResult) {
                return $cachedResult;
            }
        }

        $result = $this->db->get_col($query);
        if ($useCache) {
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
    public function hasWhereClause(string $field, ?string $operator = null)
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

        // SET values must come before WHERE values to match SQL clause order
        $allValues = array_merge($this->setValuesToPrepare, $this->valuesToPrepare);
        $prepared_query = $this->prepareQuery($query, $allValues);

        $result = $this->db->query($prepared_query);

        if ('insert' === $this->operation) {
            return $this->db->insert_id ?: $result;
        }

        return $result;
    }

    public function getSqlQuery()
    {
        $query = $this->buildQuery();
        // SET values must come before WHERE values to match SQL clause order
        $allValues = array_merge($this->setValuesToPrepare, $this->valuesToPrepare);
        return $this->prepareQuery($query, $allValues);
    }

    /**
     * Get all results from a query.
     * If this is a live query (i.e. the query has a live date range), this function will
     * split the query into two parts: a historical part that can be safely cached, and a live
     * part that should not be cached.
     * If this is not a live query, the function will simply return the result of the query.
     *
     * @param string $networkIntent      A NetworkMerge intent, or '' for "not eligible" (the
     *                                   default). Only NetworkMerge::SUM is accepted here: a
     *                                   grouped report's `counthits` is COUNT(*), which is
     *                                   additive; anything else must not be silently summed.
     * @param string $selectNoAggregate  The non-aggregate select list, re-selected outside the
     *                                   union.
     * @param string $groupBy            The group key, re-applied outside the union.
     * @param string $orderBy            The sort, re-applied outside the union.
     *
     * @return array The result of the query
     */
    public function getAll(string $networkIntent = '', string $selectNoAggregate = '', string $groupBy = '', string $orderBy = '', string $extraAggregate = '', int $outerLimit = 0)
    {
        // D22 — a network-wide read is ONE query over a union of blogs, and it takes this path
        // instead of the live/historical partitioning below.
        //
        // Not an optimisation skipped for convenience: that partitioning splits the query by
        // date range and merges the halves in PHP, and wrapping each half in its own union would
        // union N blogs twice and then merge two already-merged sets. The partitioning exists to
        // make results cacheable, and a cross-blog result is not cacheable under a per-blog key
        // anyway — so the two mechanisms have nothing to trade.
        if (NetworkMerge::SUM === $networkIntent && NetworkMerge::isMerging()) {
            $query = $this->prepareQuery($this->buildQuery(), $this->valuesToPrepare);

            // The outer aggregates: the merge itself, plus whatever aggregate the caller adds
            // (`MAX(dt) AS dt` on the "Recent …" reports). Both belong outside the union — an
            // aggregate computed per arm and never re-computed is a per-blog answer wearing a
            // network-wide label.
            $aggregates = 'SUM(counthits) AS counthits'
                . ('' === $extraAggregate ? '' : ', ' . $extraAggregate);

            $sql = (string) apply_filters(
                'slimstat_get_results_sql',
                $query,
                $selectNoAggregate,
                $orderBy,
                $groupBy,
                $aggregates
            );

            // The bound the callers deliberately left OFF the inner query (a LIMIT inside a
            // union arm loses each blog's rank-N+1 rows), re-applied OUTSIDE the union: the
            // rewriter's output ends `… GROUP BY … ORDER BY …`, and the caller's ORDER BY
            // carries full tie-breakers, so the cut equals the callers' own array_slice —
            // but MySQL top-Ns the outer sort and the wire carries `limit` rows instead of
            // one row per (blog, group) across the whole network.
            if ($outerLimit > 0) {
                $sql .= ' LIMIT ' . $outerLimit;
            }

            return (array) $this->db->get_results($sql, ARRAY_A);
        }

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

        // HAVING cannot survive the split. It filters groups AFTER aggregation, so running
        // it against each half independently asks a different question of each: "Top Bounce
        // Pages" (HAVING COUNT(visit_id) = 1) lets a page with one visit before midnight and
        // one after pass in BOTH halves, then merges them into a page with two visits — the
        // exact opposite of what the report is for. No merge can undo that, because the rows
        // that should have been excluded were already selected. Run one query instead and
        // give up only the historical half's cache entry. (D5)
        if ($split && !empty($this->havingClauses)) {
            $split = false;
        }

        if ($split) {
            $baseWhereClauses    = $this->whereClauses;
            $baseValuesToPrepare = $this->valuesToPrepare;
            array_splice($baseWhereClauses, $dtClauseIdx, 1);
            $baseValues = $baseValuesToPrepare;
            array_splice($baseValues, $dtIdx, 2);

            // Remove OFFSET from sub-queries: each partition is smaller
            // than the full date range, so applying the original OFFSET to
            // each one independently can skip past all rows in that
            // partition.  Instead, fetch without OFFSET and apply it after
            // merging.
            $parsedOffset = 0;
            $parsedLimit  = 0;
            // Cast: a query with no LIMIT leaves this null, and passing null to preg_match
            // is deprecated from PHP 8.1 — it was emitting two notices per split query, i.e.
            // on the default dashboard view of every install.
            $limitClause  = (string) $this->limitClause;
            if (preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $limitClause, $m)) {
                $parsedLimit  = intval($m[1]);
                $parsedOffset = intval($m[2]);
            } elseif (preg_match('/LIMIT\s+(\d+)/i', $limitClause, $m)) {
                $parsedLimit = intval($m[1]);
            }
            // Sub-queries fetch up to offset+limit rows (no OFFSET) so we
            // have enough data to slice after merging.
            $subLimit = $parsedOffset + $parsedLimit;

            // Clone for historical
            $histQuery                  = clone $this;
            $histQuery->whereClauses    = $baseWhereClauses;
            $histQuery->valuesToPrepare = $baseValues;
            $histQuery->whereDate('dt', ['from' => $histFrom, 'to' => $histTo]);
            $histQuery->allowCaching(true, $this->cacheExpiration);
            if ($subLimit > 0) {
                $histQuery->limit($subLimit);
            }
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
            if ($subLimit > 0) {
                $liveQuery->limit($subLimit);
            }
            try {
                $live = $liveQuery->getAll();
            } catch (Exception $e) {
                $live = [];
            }

            if (is_array($live)) {
                $dtList = array_map(fn ($row) => $row['dt'] ?? null, $live);
            }

            // Only group-merge when the query has GROUP BY (aggregate queries).
            // Raw SELECT queries (e.g. get_recent) must preserve duplicate rows.
            if (!empty($this->groupByClause)) {
                $merged = $this->mergeGroupResults($live, $historical);
            } else {
                $merged = array_merge($live, $historical);
            }

            // Re-sort merged results to honour the original ORDER BY.
            // mergeGroupResults() sums counthits but loses sort order.
            $merged = $this->sortMergedResults($merged);

            if (is_array($merged)) {
                $dtList = array_map(fn ($row) => $row['dt'] ?? null, $merged);
            }

            // Apply the original OFFSET+LIMIT after merging.
            // Check $parsedLimit (not $parsedOffset) so "top" reports
            // (which use LIMIT without OFFSET) also get capped after the
            // two partitions are merged and re-sorted.
            if ($parsedLimit > 0 && is_array($merged)) {
                $merged = array_slice($merged, $parsedOffset, $parsedLimit);
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
