<?php
/** Report denominator and evidence safeguards. @license GPL-2.0-or-later */
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../src/Forms/Metrics.php';
require __DIR__ . '/../src/Forms/Insights.php';
use SlimStat\Forms\Insights;
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
check( ! Insights::mobileGap( array( 'seen' => 10, 'converted' => 1 ), array( 'seen' => 10, 'converted' => 9 ) ), 'Small samples cannot trigger a recommendation.' );
check( ! Insights::mobileGap( array( 'seen' => 500, 'converted' => 50 ), array( 'seen' => 500, 'converted' => 55 ) ), 'Ordinary sampling variation is not an opportunity.' );
check( Insights::mobileGap( array( 'seen' => 500, 'converted' => 20 ), array( 'seen' => 500, 'converted' => 100 ) ), 'A substantial supported gap can prompt a check.' );
check( ! Insights::mobileGap( array( 'seen' => 500, 'converted' => 100 ), array( 'seen' => 500, 'converted' => 20 ) ), 'Do not reverse mobile and desktop.' );
check( ! Insights::mobileGap( array( 'seen' => 100, 'converted' => 101 ), array( 'seen' => 100, 'converted' => 20 ) ), 'Reject inconsistent counters.' );
check( ! Insights::mobileGap( array(), array() ), 'Missing evidence is not a zero rate.' );
echo "Form report evidence tests passed.\n";
