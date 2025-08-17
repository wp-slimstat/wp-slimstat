<?php

namespace SlimStat\Helpers;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

class DataBuckets
{
    private array $labels = [];

    private array $prev_labels = [];

    private array $datasets = ['v1' => [], 'v2' => []];

    private array $datasetsPrev = ['v1' => [], 'v2' => []];

    private array $totals;

    private string $labelFormat;

    private string $gran;

    private string $tzOffset;

    private int $start;

    private int $end;

    private int $prevStart;

    private int $prevEnd;

    private int $points;

    public function __construct(string $labelFormat, string $gran, int $start, int $end, int $prevStart, int $prevEnd, array $totals = [])
    {
        global $wpdb;

        $this->labelFormat = $labelFormat;
        $this->gran        = $gran;
        $this->start       = $start;
        $this->end         = $end;
        $this->prevStart   = $prevStart;
        $this->prevEnd     = $prevEnd;
        $this->totals      = $totals;

        $offset_seconds = $wpdb->get_var('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())');
        $sign           = ($offset_seconds < 0) ? '-' : '+';
        $abs            = abs($offset_seconds);
        $h              = floor($abs / 3600);
        $m              = floor(($abs % 3600) / 60);
        $tzOffset       = sprintf('%s%02d:%02d', $sign, $h, $m);
        $this->tzOffset = $tzOffset;

        $this->initBuckets();
    }

    private function initBuckets(): void
    {
        switch ($this->gran) {
            case 'HOUR':
                $this->initSeq(3600);
                break;
            case 'DAY':
                $this->initSeq(86400);
                break;
            case 'WEEK':
                $this->initSeqWeek();
                break;
            case 'MONTH':
                $this->initSeqMonth();
                break;
            case 'YEAR':
                $this->initSeqYear();
                break;
        }
    }

    private function initSeq(int $interval): void
    {
        $range = $this->end - $this->start;
        $count = (int)ceil($range / $interval);
        $time  = $this->start;
        for ($i = 0; $i < $count; $i++) {
            $label          = date($this->labelFormat, $time);
            $this->labels[] = sprintf("'%s'", $label);
            foreach (['v1', 'v2'] as $k) {
                $this->datasets[$k][]     = 0;
                $this->datasetsPrev[$k][] = 0;
            }

            $time += $interval;
        }

        $this->points = $count;
    }

    private function initSeqWeek(): void
    {
        $start       = (new \DateTime())->setTimestamp($this->start);
        $end         = (new \DateTime())->setTimestamp($this->end);
        $startOfWeek = get_option('start_of_week', 1);

        // Adjust start to the first day of the week
        $firstLabel     = $start->format($this->labelFormat);
        $this->labels[] = sprintf("'%s'", $firstLabel);
        foreach (['v1', 'v2'] as $k) {
            $this->datasets[$k][]     = 0;
            $this->datasetsPrev[$k][] = 0;
        }

        // Move start to the next week if it is not the start of the week
        $start->modify('next ' . jddayofweek($startOfWeek - 1, 1));
        if ($start->getTimestamp() <= $this->start) {
            $start->modify('+1 week');
        }

        // Generate labels for each week
        while ($start <= $end) {
            $label          = $start->format($this->labelFormat);
            $this->labels[] = sprintf("'%s'", $label);
            foreach (['v1', 'v2'] as $k) {
                $this->datasets[$k][]     = 0;
                $this->datasetsPrev[$k][] = 0;
            }

            $start->modify('+1 week');
        }

        $this->points = count($this->labels);
    }

    private function initSeqMonth(): void
    {
        $date = (new \DateTime())->setTimestamp($this->start)->modify('first day of this month')->modifY('midnight');
        $end  = (new \DateTime())->setTimestamp($this->end);
        while ($date <= $end) {
            $label          = $date->format($this->labelFormat);
            $this->labels[] = sprintf("'%s'", $label);
            foreach (['v1', 'v2'] as $k) {
                $this->datasets[$k][]     = 0;
                $this->datasetsPrev[$k][] = 0;
            }

            $date->modify('+1 month');
        }

        $this->points = count($this->labels);
    }

    private function initSeqYear(): void
    {
        $startYear = (int)date('Y', $this->start);
        $endYear   = (int)date('Y', $this->end);
        for ($y = $startYear; $y <= $endYear; $y++) {
            $this->labels[] = sprintf("'%d'", $y);
            foreach (['v1', 'v2'] as $k) {
                $this->datasets[$k][]     = 0;
                $this->datasetsPrev[$k][] = 0;
            }
        }

        $this->points = count($this->labels);
    }

    public function addRow(int $dt, int $v1, int $v2, string $period): void
    {
        $base = 'current' === $period ? $this->start : $this->prevStart;
        $base = strtotime(date('Y-m-d H:i:s', $base));

        $dt    = strtotime(wp_date('Y-m-d H:i:s', $dt, new \DateTimeZone($this->tzOffset)));
        $start = $this->start;
        if ('HOUR' === $this->gran) {
            $dt     = strtotime(date('Y-m-d H:00:00', $dt));
            $offset = floor(($dt - $base) / 3600);
        } elseif ('DAY' === $this->gran) {
            $offset = floor(($dt - $base) / 86400);
        } elseif ('MONTH' === $this->gran) {
            $start  = new \DateTime('@' . $base);
            $start  = $start->modify('first day of previous month')->modify('midnight');
            $target = new \DateTime('@' . $dt);
            if ($target->getTimestamp() < $start->getTimestamp()) {
                $offset = -1;
            } else {
                $diff   = $start->diff($target);
                $offset = $diff->y * 12 + $diff->m;
            }
        } elseif ('WEEK' === $this->gran) {
            $offset = date('W', $dt) - date('W', $base) + (date('Y', $dt) - date('Y', $base)) * 52;
            if ($offset < 0) {
                $offset = -1;
            }
        } elseif ('YEAR' === $this->gran) {
            $offset = (new \DateTime('@' . $base))->diff(new \DateTime('@' . $dt))->y;
        } else {
            $offset = 0; // fallback default
        }

        // Ensure offset is within bounds
        if ($offset <= $this->points) {
            $target = 'current' === $period ? 'datasets' : 'datasetsPrev';
            if (!isset($this->{$target}['v1'][$offset])) {
                $this->{$target}['v1'][$offset] = 0;
            }

            $this->{$target}['v1'][$offset] += $v1;
            if (!isset($this->{$target}['v2'][$offset])) {
                $this->{$target}['v2'][$offset] = 0;
            }

            $this->{$target}['v2'][$offset] += $v2;
        }
    }

    public function mapPrevLabels(array $labels, array $params): void
    {
        $this->prev_labels = array_map(fn ($label, $index) => date($params['data_points_label'], strtotime(sprintf('+%s %s', $index, $params['granularity']), $params['previous_start'])), $labels, array_keys($labels));
    }

    private function shiftDatasets(): void
    {
        foreach (['v1', 'v2'] as $k) {
            if (isset($this->datasets[$k][-1])) {
                $newKeys            = array_map(fn ($key) => $key + 1, array_keys($this->datasets[$k]));
                $this->datasets[$k] = array_combine($newKeys, array_values($this->datasets[$k]));
                ksort($this->datasets[$k]);
                if (empty(end($this->datasets[$k]))) {
                    array_pop($this->datasets[$k]);
                }
            }

            if (isset($this->datasetsPrev[$k][-1])) {
                $newKeys                = array_map(fn ($key) => $key + 1, array_keys($this->datasetsPrev[$k]));
                $this->datasetsPrev[$k] = array_combine($newKeys, array_values($this->datasetsPrev[$k]));
                ksort($this->datasetsPrev[$k]);
                if (empty(end($this->datasetsPrev[$k]))) {
                    array_pop($this->datasetsPrev[$k]);
                }
            }
        }
    }

    public function toArray(): array
    {
        $this->shiftDatasets();

        $this->mapPrevLabels($this->labels, [
            'data_points_label' => $this->labelFormat,
            'granularity'       => $this->gran,
            'previous_start'    => $this->prevStart,
        ]);

        return [
            'labels'        => $this->labels,
            'totals'        => $this->totals,
            'prev_labels'   => $this->prev_labels,
            'datasets'      => $this->datasets,
            'datasets_prev' => $this->datasetsPrev,
            'today'         => 'WEEK' === $this->gran && wp_date('YW', $this->end, wp_timezone()) === wp_date('YW', time(), wp_timezone()) ? str_replace("'", '', $this->labels[count($this->labels) - 1]) : wp_date($this->labelFormat, time(), wp_timezone()),
            'granularity'   => $this->gran,
        ];
    }
}
