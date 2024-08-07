<?php

namespace SlimStat\Dependencies\MatthiasMullie\Scrapbook\Adapters\Collections;

use SlimStat\Dependencies\MatthiasMullie\Scrapbook\Adapters\Collections\Utils\PrefixKeys;
use SlimStat\Dependencies\MatthiasMullie\Scrapbook\Adapters\SQL as Adapter;

/**
 * SQL adapter for a subset of data, accomplished by prefixing keys.
 *
 * @author Matthias Mullie <scrapbook@mullie.eu>
 * @copyright Copyright (c) 2014, Matthias Mullie. All rights reserved
 * @license LICENSE MIT
 */
class SQL extends PrefixKeys
{
    /**
     * @var \PDO
     */
    protected $client;

    /**
     * @var string
     */
    protected $table;

    /**
     * @param string $table
     * @param string $name
     */
    public function __construct(Adapter $cache, \PDO $client, $table, $name)
    {
        parent::__construct($cache, 'collection:'.$name.':');
        $this->client = $client;
        $this->table = $table;
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        // deleting key with a prefixed LIKE should be fast, they're indexed
        $statement = $this->client->prepare(
            "DELETE FROM $this->table
            WHERE k LIKE :key"
        );

        return $statement->execute(array(
            ':key' => $this->prefix.'%',
        ));
    }
}
