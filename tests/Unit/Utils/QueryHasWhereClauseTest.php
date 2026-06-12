<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Utils;

use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/** Implicit-nullable signature guard for Query::hasWhereClause. */
class QueryHasWhereClauseTest extends WpSlimstatTestCase
{
    public function test_signature_declares_nullable_operator(): void
    {
        $reflect = new \ReflectionMethod(\SlimStat\Utils\Query::class, 'hasWhereClause');
        $params  = $reflect->getParameters();
        $this->assertCount(2, $params, 'hasWhereClause must take 2 params');

        $field = $params[0];
        $this->assertSame('string', (string) $field->getType(), '$field must be typed string');

        $operator = $params[1];
        $type = $operator->getType();
        $this->assertNotNull($type, '$operator must have an explicit type');
        $this->assertTrue($type->allowsNull(), '$operator must be nullable string');
    }
}
