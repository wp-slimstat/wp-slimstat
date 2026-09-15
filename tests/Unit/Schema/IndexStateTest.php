<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Schema;

use SlimStat\Schema\Schema;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class IndexStateTest extends WpSlimstatTestCase
{
    private function rows(): array
    {
        return [
            ['Key_name' => 'idx_events_notes_dt', 'Seq_in_index' => '1', 'Column_name' => 'dt', 'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'BTREE', 'Collation' => 'A'],
            ['Key_name' => 'idx_events_notes_dt', 'Seq_in_index' => '2', 'Column_name' => 'notes', 'Sub_part' => '64', 'Non_unique' => '1', 'Index_type' => 'BTREE', 'Collation' => 'A'],
        ];
    }

    private function state($rows, string $error = ''): array
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->last_error = $error;
        $db->shouldReceive('suppress_errors')->andReturn(false);
        $db->shouldReceive('get_results')->with('SHOW INDEX FROM `wp_slim_events`', ARRAY_A)->once()->andReturn($rows);
        $db->shouldReceive('get_col')->never();
        return Schema::indexState($db, 'slim_events', 'wp_');
    }

    public function test_valid_metadata_is_normalized_and_sorted_by_sequence(): void
    {
        $rows = array_reverse($this->rows());
        $rows[0]['Sub_part'] = 64;
        $rows[0]['Non_unique'] = 1;
        $state = $this->state($rows);
        $this->assertSame(['idx_events_notes_dt'], $state['present']);
        $this->assertSame(['{prefix}slim_stat_events_idx'], $state['missing']);
        $this->assertSame([], $state['malformed']);
    }

    public function test_same_name_is_not_sufficient_for_any_malformed_definition(): void
    {
        $cases = [];
        foreach (['Column_name' => 'id', 'Sub_part' => 32, 'Non_unique' => 0, 'Index_type' => 'HASH', 'Collation' => 'D', 'Seq_in_index' => 1, 'Visible' => 'NO', 'Ignored' => 'YES'] as $field => $value) {
            $rows = $this->rows();
            $rows[1][$field] = $value;
            $cases[$field] = $rows;
        }
        $rows = $this->rows();
        $rows[0]['Seq_in_index'] = 2;
        $rows[1]['Seq_in_index'] = 1;
        $cases['wrong order'] = $rows;
        $cases['missing column'] = [$this->rows()[0]];
        $rows = $this->rows();
        unset($rows[1]['Sub_part']);
        $cases['incomplete metadata'] = $rows;
        foreach ($cases as $label => $rows) {
            $state = $this->state($rows);
            $this->assertSame([], $state['present'], $label);
            $this->assertSame(['idx_events_notes_dt'], $state['malformed'], $label);
            $this->assertNotContains('idx_events_notes_dt', $state['missing'], $label . ': must not CREATE a duplicate name');
        }
    }

    public function test_unreadable_metadata_cannot_authorize_ddl_or_stamps(): void
    {
        foreach ([[null, ''], [[], 'access denied'], [[['unexpected' => 'shape']], '']] as [$rows, $error]) {
            $state = $this->state($rows, $error);
            $this->assertSame([], $state['present']);
            $this->assertSame([], $state['missing']);
        }
    }
}
