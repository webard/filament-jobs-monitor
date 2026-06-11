<?php

namespace Croustibat\FilamentJobsMonitor\Resources\QueueMonitorResource\Widgets;

use Croustibat\FilamentJobsMonitor\Models\FailureGroup;
use Croustibat\FilamentJobsMonitor\Models\QueueMonitor;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class FailureStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $openGroups = resolve(FailureGroup::class)::whereNull('resolved_at')->count();
        $newToday = resolve(FailureGroup::class)::whereNull('resolved_at')
            ->where('first_occurred_at', '>=', now()->startOfDay())
            ->count();

        $failuresLastHour = resolve(QueueMonitor::class)::where('failed', true)
            ->where('finished_at', '>=', now()->subHour())
            ->count();
        $failuresPreviousHour = resolve(QueueMonitor::class)::where('failed', true)
            ->whereBetween('finished_at', [now()->subHours(2), now()->subHour()])
            ->count();
        $hourDelta = $failuresLastHour - $failuresPreviousHour;

        $jobsLast24h = resolve(QueueMonitor::class)::where('started_at', '>=', now()->subDay())->count();
        $failedLast24h = resolve(QueueMonitor::class)::where('failed', true)
            ->where('started_at', '>=', now()->subDay())
            ->count();
        $failureRate = $jobsLast24h > 0 ? round($failedLast24h / $jobsLast24h * 100, 2) : 0.0;

        $resolvedLast7d = resolve(FailureGroup::class)::where('resolved_at', '>=', now()->subDays(7))->count();

        return [
            Stat::make(__('filament-jobs-monitor::translations.open_groups'), Number::format($openGroups))
                ->description(__('filament-jobs-monitor::translations.new_today', ['count' => $newToday]))
                ->descriptionIcon('heroicon-m-bug-ant')
                ->color($openGroups > 0 ? 'danger' : 'gray'),
            Stat::make(__('filament-jobs-monitor::translations.failures_last_hour'), Number::format($failuresLastHour))
                ->description(__('filament-jobs-monitor::translations.vs_previous_hour', ['delta' => ($hourDelta >= 0 ? '+' : '').$hourDelta]))
                ->descriptionIcon($hourDelta > 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($failuresLastHour > 0 ? 'danger' : 'gray'),
            Stat::make(__('filament-jobs-monitor::translations.failure_rate'), $failureRate.'%')
                ->description(__('filament-jobs-monitor::translations.of_jobs_24h', ['count' => Number::format($jobsLast24h)]))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('warning'),
            Stat::make(__('filament-jobs-monitor::translations.resolved_7d'), Number::format($resolvedLast7d))
                ->description(__('filament-jobs-monitor::translations.resolved_7d_description'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
        ];
    }
}
