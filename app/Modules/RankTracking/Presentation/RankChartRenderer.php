<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Presentation;

use App\Core\Localization\Translator;
use App\Core\Security\Html;
use App\Modules\RankTracking\Application\RankDashboardService;

final class RankChartRenderer
{
    public function __construct(private readonly Translator $translator) {}

    public function render(array $model): string
    {
        $width = 900; $height = 360; $left = 54; $top = 20; $plotWidth = 820; $plotHeight = 290;
        $timestamps = [];
        foreach ($model['series'] as $points) foreach ($points as $point) $timestamps[] = strtotime((string) $point['observed_at']) ?: 0;
        $min = $timestamps === [] ? 0 : min($timestamps); $max = $timestamps === [] ? 0 : max($timestamps);
        $svg = '<svg class="rank-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" data-axis-direction="inverted" aria-label="' . Html::escape($this->translator->get('chart_description')) . '">';
        foreach ($model['thresholds'] as $threshold) {
            if ($threshold > $model['max_position']) continue;
            $y = $top + RankDashboardService::y((int) $threshold, (int) $model['max_position'], $plotHeight);
            $svg .= '<line class="threshold" x1="' . $left . '" y1="' . $y . '" x2="' . ($left + $plotWidth) . '" y2="' . $y . '"><title>' . Html::escape($this->translator->get('top', ['count' => $threshold])) . '</title></line><text x="4" y="' . ($y + 4) . '">#' . $threshold . '</text>';
        }
        foreach (['desktop' => '#2457d6', 'mobile' => '#c2418c'] as $device => $color) {
            $segments = [[]];
            foreach ($model['series'][$device] ?? [] as $point) {
                if ($point['position'] === null) { if ($segments[array_key_last($segments)] !== []) $segments[] = []; continue; }
                $time = strtotime((string) $point['observed_at']) ?: $min;
                $x = $left + ($max === $min ? $plotWidth / 2 : (($time - $min) / ($max - $min)) * $plotWidth);
                $y = $top + RankDashboardService::y((int) $point['position'], (int) $model['max_position'], $plotHeight);
                $segments[array_key_last($segments)][] = [$x, $y, $point];
            }
            foreach ($segments as $segment) {
                if ($segment === []) continue;
                $coordinates = implode(' ', array_map(static fn (array $point): string => $point[0] . ',' . $point[1], $segment));
                $svg .= '<polyline class="series ' . $device . '" style="stroke:' . $color . '" points="' . $coordinates . '" fill="none" />';
                foreach ($segment as [$x, $y, $point]) $svg .= '<circle style="fill:' . $color . '" cx="' . $x . '" cy="' . $y . '" r="4"><title>' . Html::escape($this->translator->get($device) . ' #' . $point['position'] . ' — ' . $point['observed_at']) . '</title></circle>';
            }
        }
        if ($timestamps !== []) {
            $svg .= '<text x="' . $left . '" y="345">' . Html::escape(gmdate('Y-m-d H:i', $min)) . '</text><text text-anchor="end" x="' . ($left + $plotWidth) . '" y="345">' . Html::escape(gmdate('Y-m-d H:i', $max)) . '</text>';
        }
        return $svg . '</svg>';
    }
}
