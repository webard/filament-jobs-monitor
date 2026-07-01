<?php

use Croustibat\FilamentJobsMonitor\Resources\QueueMonitorResource;

return [

    'connection' => null,

    'resources' => [
        'enabled' => true,
        'label' => 'Job',
        'plural_label' => 'Jobs',
        'navigation_group' => 'Settings',
        'navigation_icon' => 'heroicon-o-cpu-chip',
        'navigation_sort' => null,
        'navigation_count_badge' => false,
        'resource' => QueueMonitorResource::class,
        'cluster' => null,
        /**
         * Configure the sub-navigation position for the resource pages.
         * Options: Filament\Pages\Enums\SubNavigationPosition::Top or ::Sidebar
         * Default: null (uses Filament default)
         */
        'sub_navigation_position' => null,
    ],
    'failures' => [
        /**
         * Enable the "Failures" page: failed jobs grouped by signature
         * (exception class + job class + normalised message).
         */
        'enabled' => true,
        /**
         * Table polling interval (null to disable).
         */
        'polling_interval' => '10s',
    ],
    'pruning' => [
        'enabled' => true,
        'retention_days' => 7,
    ],
    'queues' => [
        'default',
    ],
    'tenancy' => [
        'enabled' => false,
        'model' => null,
        'column' => 'tenant_id',
    ],
];
