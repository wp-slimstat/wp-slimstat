<?php

namespace SlimStat\Helpers;

class DataBuckets
{
    private array $labels = [];
    private array $prev_labels = [];
    private array $datasets = ['v1' => [], 'v2' => []];
    private array $datasetsPrev = ['v1' => [], 'v2' => []];
    private string $labelFormat;
    private string $gran;
    private int $start;
    private int $end;
    private int $prevStart;
    private int $prevEnd;
    private int $points;

    public function __construct(string $labelFormat, string $gran, int $start, int $end, int $prevStart, int $prevEnd)
    {
        $this->labelFormat = $labelFormat;
        $this->gran        = $gran;
        $this->start       = $start;
        $this->end         = $end;
        $this->prevStart   = $prevStart;
        $this->prevEnd     = $prevEnd;
        $this->prev_labels = [];

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
                $this->initSeqWeek(); // 7 days
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
            $label = date($this->labelFormat, $time);
            $this->labels[] = "'$label'";
            foreach (['v1','v2'] as $k) { $this->datasets[$k][] = 0; $this->datasetsPrev[$k][] = 0; }
            $time += $interval;
        }
        $this->points = $count;
    }

    private function initSeqWeek(): void
    {
        $start = (new \DateTime())->setTimestamp($this->start);
        $end   = (new \DateTime())->setTimestamp($this->end);
        $interval = new \DateInterval('P1W');

        while ($start <= $end) {
            $label = $start->format($this->labelFormat);
            $this->labels[] = "'$label'";
            foreach (['v1','v2'] as $k) { $this->datasets[$k][] = 0; $this->datasetsPrev[$k][] = 0; }
            $start->add($interval);
        }
        $this->points = count($this->labels);
    }

    private function initSeqMonth(): void
    {
        $date = (new \DateTime())->setTimestamp($this->start);
        $end  = (new \DateTime())->setTimestamp($this->end);
        while ($date <= $end) {
            $label = $date->format($this->labelFormat);
            $this->labels[] = "'$label'";
            foreach (['v1','v2'] as $k) { $this->datasets[$k][] = 0; $this->datasetsPrev[$k][] = 0; }
            $date->modify('+1 month');
        }
        $this->points = count($this->labels);
    }

    private function initSeqYear(): void
    {
        $startYear = (int)date('Y', $this->start);
        $endYear   = (int)date('Y', $this->end);
        for ($y = $startYear; $y <= $endYear; $y++) {
            $this->labels[] = "'$y'";
            foreach (['v1','v2'] as $k) { $this->datasets[$k][] = 0; $this->datasetsPrev[$k][] = 0; }
        }
        $this->points = count($this->labels);
    }

    public function addRow(int $dt, int $v1, int $v2, string $period): void
    {
        $base = $period === 'current' ? $this->start : $this->prevStart;

        $offset = match ($this->gran) {
            'HOUR'  => (int)floor(($dt - $base) / 3600),
            'DAY'   => (int)floor(($dt - $base) / 86400),
            'MONTH' => (function () use ($base, $dt) {
                $start = new \DateTime("@$base");
                $target = new \DateTime("@$dt");
                if ($target < $start) {
                    return -1;
                }
                $diff = $start->diff($target);
                return $diff->y * 12 + $diff->m;
            })(),
            'WEEK'  => (function () use ($base, $dt) {
                $start = new \DateTime("@$base");
                $target = new \DateTime("@$dt");
                if ($target < $start) {
                    return -1;
                }
                $diff = $start->diff($target);
                return $diff->y * 52 + (int)floor($diff->days / 7);
            })(),
            'YEAR'  => (new \DateTime("@$base"))->diff(new \DateTime("@$dt"))->y,
        };

        // Ensure offset is within bounds
        if ($offset < $this->points) {
            $target = $period === 'current' ? 'datasets' : 'datasetsPrev';
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
        $this->prev_labels = array_map(function ($label, $index) use ($params) {
            return date($params['data_points_label'], strtotime("+{$index} {$params['granularity']}", $params['previous_start']));
        }, $labels, array_keys($labels));
    }

    public function toArray(): array
    {
        foreach (['v1', 'v2'] as $k) {
            if (isset($this->datasets[$k][-1])) {
                $newKeys = array_map(fn($key) => $key + 1, array_keys($this->datasets[$k]));
                $this->datasets[$k] = array_combine($newKeys, array_values($this->datasets[$k]));
                ksort($this->datasets[$k]);
                if (empty(end($this->datasets[$k]))) {
                    array_pop($this->datasets[$k]);
                }
            }

            if (isset($this->datasetsPrev[$k][-1])) {
                $newKeys = array_map(fn($key) => $key + 1, array_keys($this->datasetsPrev[$k]));
                $this->datasetsPrev[$k] = array_combine($newKeys, array_values($this->datasetsPrev[$k]));
                ksort($this->datasetsPrev[$k]);
                if (empty(end($this->datasetsPrev[$k]))) {
                    array_pop($this->datasetsPrev[$k]);
                }
            }
        }

        $this->mapPrevLabels($this->labels, [
            'data_points_label' => $this->labelFormat,
            'granularity'       => $this->gran,
            'previous_start'    => $this->prevStart,
        ]);

        return [
            'labels'         => $this->labels,
            'prev_labels'    => $this->prev_labels,
            'datasets'       => $this->datasets,
            'datasets_prev'  => $this->datasetsPrev,
            'today'          => wp_date($this->labelFormat, time(), wp_timezone()),
            'granularity'    => $this->gran,
        ];
    }
}