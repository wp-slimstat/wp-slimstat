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
     * @param string|int $to End date (Y-m-d or Y-m-d H:i:s or timestamp)
     * @return void
     */
    public function canUseCacheForDateRange($to)
    {
        $today = $this->getTodayDate();

        // Convert $to to timestamp if it's a string
        $toTs = is_numeric($to) ? intval($to) : strtotime($to);

        // Cache should be used if the date range does not include today
        if ($toTs < $today) {
            $this->allowCaching(true, $this->cacheExpiration);
        } else {
            // If date range includes today, disable caching to get fresh data
            $this->allowCaching(false);
        }
    }

    /**
     * Get today's date in Y-m-d format (server time).
     * @return string
     */
    protected function getTodayDate()
    {
        return strtotime(date('Y-m-d 00:00:00'));
    }

    protected function getSplitDateRanges2()
    {
        $dtField = 'dt';
        $todayStart = $this->getTodayDate();
        $now = time();
        // Find the correct dt BETWEEN clause and its values
        foreach ($this->whereClauses as $idx => $clause) {
            if (preg_match('/' . $dtField . ' BETWEEN %s AND %s/', $clause)) {
                // Try to get the correct from/to for this clause
                $from = null;
                $to = null;
                // Find the index of this clause in valuesToPrepare
                $dtIdx = 0;
                foreach ($this->whereClauses as $i => $c) {
                    if ($i == $idx) break;
                    if (preg_match('/%s/', $c)) $dtIdx += substr_count($c, '%s');
                }
                $from = $this->valuesToPrepare[$dtIdx] ?? null;
                $to = $this->valuesToPrepare[$dtIdx+1] ?? null;
                // Normalize to timestamp
                $fromTs = is_numeric($from) ? intval($from) : strtotime($from);
                $toTs = is_numeric($to) ? intval($to) : strtotime($to);
                // If range covers today, split
                if ($fromTs !== null && $toTs !== null && $fromTs < $todayStart && $toTs >= $todayStart) {
                    return [true, $fromTs, $todayStart - 1, $todayStart, $toTs];
                }
            }
        }
        return [false, null, null, null, null];
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

    protected function prepareQuery($query, $args = [])
    {
        if (preg_match('/%[i|s|f|d]/', $query)) {
            // Count placeholders in the query
            $placeholder_count = preg_match_all('/%[i|s|f|d]/', $query, $matches);
            if ($placeholder_count === 1) {
                // Always pass a single value, not an array, for a single placeholder
                if (is_array($args)) {
                    $query = $this->db->prepare($query, reset($args));
                } else {
                    $query = $this->db->prepare($query, $args);
                }
            } elseif (is_array($args) && count($args) === $placeholder_count) {
                $query = $this->db->prepare($query, $args);
            } else {
                // Mismatch: fallback to original query or log error
                // Optionally, you can log this error for debugging
                // error_log('SlimStat: Placeholder/argument count mismatch in prepareQuery.');
                $query = $this->db->prepare($query, $args);
            }
        }
        return $query;
    }

    protected function getCacheKeyForQuery($query, $args = [])
    {
        $data = [
            'query' => $query,
            'args' => $args,
        ];
        $hash = substr(md5(serialize($data)), 0, 16);
        return 'wp_slimstat_query_' . $hash;
    }

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

    protected function setCachedResultForQuery($query, $args, $result, $expiration = 300)
    {
        $cacheKey = $this->getCacheKeyForQuery($query, $args);
        $data = serialize($result);
        /*
        if (function_exists('gzcompress')) {
            $data = gzcompress($data);
        }
        */
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
     * Helper: Check if the current whereClauses include a dt BETWEEN covering today
     * Returns array with [isSplitNeeded, historicalFrom, historicalTo, liveFrom, liveTo, dtIdx, dtClauseIdx]
     */
    protected function getSplitDateRanges()
    {
        $dtField = 'dt';
        $todayStart = $this->getTodayDate();
        // Find the correct dt BETWEEN clause and its values
        foreach ($this->whereClauses as $idx => $clause) {
            if (preg_match('/' . $dtField . ' BETWEEN %s AND %s/', $clause)) {
                // Find the index of this clause in valuesToPrepare
                $dtIdx = 0;
                foreach ($this->whereClauses as $i => $c) {
                    if ($i == $idx) break;
                    if (preg_match('/%s/', $c)) $dtIdx += substr_count($c, '%s');
                }
                $from = $this->valuesToPrepare[$dtIdx] ?? null;
                $to = $this->valuesToPrepare[$dtIdx+1] ?? null;
                // Normalize to timestamp
                $fromTs = is_numeric($from) ? intval($from) : strtotime($from);
                $toTs = is_numeric($to) ? intval($to) : strtotime($to);
                // If range covers today, split
                if ($fromTs !== null && $toTs !== null && $fromTs < $todayStart && $toTs >= $todayStart) {
                    return [true, $fromTs, $todayStart - 1, $todayStart, $toTs, $dtIdx, $idx];
                }
            }
        }
        return [false, null, null, null, null, null, null];
    }

    /**
     * Helper: Merge two result sets for group by queries (by key column)
     */
    protected function mergeGroupResults($historical, $live, $groupKey = null, $sumFields = ['counthits'])
    {
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
                $baseWhereClauses = $this->whereClauses;
                $baseValuesToPrepare = $this->valuesToPrepare;
                array_splice($baseWhereClauses, $range['clauseIdx'], 1);
                array_splice($baseValuesToPrepare, $range['valueIdx'], 2);
                $data = $this->processDateRange($range['from'], $range['to'], $baseWhereClauses, $baseValuesToPrepare);
                $results = array_merge($results, $data);
            }
            return $results;
        }
        // Check if split-query is needed
        list($split, $histFrom, $histTo, $liveFrom, $liveTo, $dtIdx, $dtClauseIdx) = $this->getSplitDateRanges();
        if ($split) {
            // Remove only the dt BETWEEN clause and its values for both clones
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
            $historical = $histQuery->getAll();

            // Clone for live
            $liveQuery = clone $this;
            $liveQuery->whereClauses = $baseWhereClauses;
            $liveQuery->valuesToPrepare = $baseValues;
            $liveQuery->whereDate('dt', ['from' => $liveFrom, 'to' => $liveTo], true);
            $liveQuery->allowCaching(false, 0);
            $live = $liveQuery->getAll();

            if (is_array($live)) {
                $dtList = array_map(function($row) { return $row['dt'] ?? null; }, $live);
            }

            $groupKey = null;
            if (!empty($this->groupByClause)) {
                if (preg_match('/GROUP BY ([a-zA-Z0-9_]+)/', $this->groupByClause, $m)) {
                    $groupKey = $m[1];
                }
            }
            $merged = $this->mergeGroupResults( $live, $historical, $groupKey);
            if (is_array($merged)) {
                $dtList = array_map(function($row) { return $row['dt'] ?? null; }, $merged);
            }
            return $merged;
        }
        $query = $this->buildQuery();
        $query = $this->prepareQuery($query, $this->valuesToPrepare);
        if ($this->allowCaching) {
            $cachedResult = $this->getCachedResultForQuery($query, $this->valuesToPrepare);
            if ($cachedResult !== false) {
                return $cachedResult;
            }
        }
        $result = $this->db->get_results($query, ARRAY_A);
        if ($this->allowCaching) {
            $this->setCachedResultForQuery($query, $this->valuesToPrepare, $result, $this->cacheExpiration);
        }
        return $result;
    }

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
                // e.g. dt BETWEEN %s AND %s
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
