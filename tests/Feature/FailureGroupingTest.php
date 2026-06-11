<?php

use Croustibat\FilamentJobsMonitor\Models\FailureGroup;
use Croustibat\FilamentJobsMonitor\Models\QueueMonitor;
use Croustibat\FilamentJobsMonitor\QueueMonitorProvider;
use Croustibat\FilamentJobsMonitor\Resources\QueueMonitorResource\Widgets\FailureStatsOverview;
use Illuminate\Contracts\Queue\Job as JobContract;

beforeEach(function () {
    $migration = include __DIR__.'/../../database/migrations/create_filament-jobs-monitor_table.php.stub';
    $migration->up();

    $migration = include __DIR__.'/../../database/migrations/add_failures_to_filament-jobs-monitor_table.php.stub';
    $migration->up();
});

it('normalizes dynamic values out of exception messages', function () {
    $normalized = FailureGroup::normalizeMessage(
        "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found for order 'ord_2j8K9P3qVx' (id 4821)"
    );

    expect($normalized)
        ->toBe("SQLSTATE[<num>]: Serialization failure: <num> Deadlock found for order '<str>' (id <num>)");
});

it('normalizes uuids and long hashes', function () {
    $normalized = FailureGroup::normalizeMessage(
        'Job 9b0a3c7e-8d4e-4f5a-bd92-3c0f5ee1a8c2 failed with token 8f3acafe00112233deadbeef'
    );

    expect($normalized)->toBe('Job <uuid> failed with token <hash>');
});

it('produces the same signature for messages differing only by dynamic values', function () {
    $first = FailureGroup::makeSignature(
        'Illuminate\Database\QueryException',
        'App\Jobs\ChargeCustomerJob',
        FailureGroup::normalizeMessage('Deadlock found on order 4821'),
    );

    $second = FailureGroup::makeSignature(
        'Illuminate\Database\QueryException',
        'App\Jobs\ChargeCustomerJob',
        FailureGroup::normalizeMessage('Deadlock found on order 99'),
    );

    expect($first)->toBe($second);
});

it('produces different signatures for different exception classes', function () {
    $first = FailureGroup::makeSignature('RuntimeException', 'App\Jobs\FooJob', 'Boom');
    $second = FailureGroup::makeSignature('LogicException', 'App\Jobs\FooJob', 'Boom');

    expect($first)->not->toBe($second);
});

it('creates a failure group on first occurrence', function () {
    $group = FailureGroup::recordFailure(
        exceptionClass: 'Illuminate\Database\QueryException',
        jobClass: 'App\Jobs\ChargeCustomerJob',
        queue: 'payments',
        message: 'Deadlock found on order 4821',
    );

    expect($group->exists)->toBeTrue()
        ->and($group->occurrences_count)->toBe(1)
        ->and($group->exception_class)->toBe('Illuminate\Database\QueryException')
        ->and($group->job_class)->toBe('App\Jobs\ChargeCustomerJob')
        ->and($group->queue)->toBe('payments')
        ->and($group->message)->toBe('Deadlock found on order <num>')
        ->and($group->first_occurred_at)->not->toBeNull()
        ->and($group->resolved_at)->toBeNull()
        ->and($group->status)->toBe('open');
});

it('increments occurrences for repeated failures with the same signature', function () {
    FailureGroup::recordFailure('RuntimeException', 'App\Jobs\FooJob', 'default', 'Failed for id 1');
    $group = FailureGroup::recordFailure('RuntimeException', 'App\Jobs\FooJob', 'default', 'Failed for id 2');

    expect(FailureGroup::count())->toBe(1)
        ->and($group->occurrences_count)->toBe(2);
});

it('creates separate groups for different signatures', function () {
    FailureGroup::recordFailure('RuntimeException', 'App\Jobs\FooJob', 'default', 'Boom');
    FailureGroup::recordFailure('RuntimeException', 'App\Jobs\BarJob', 'default', 'Boom');

    expect(FailureGroup::count())->toBe(2);
});

it('reopens a resolved group when a new failure arrives', function () {
    $group = FailureGroup::recordFailure('RuntimeException', 'App\Jobs\FooJob', 'default', 'Boom');

    $group->markResolved();
    expect($group->fresh()->isResolved())->toBeTrue();

    $group = FailureGroup::recordFailure('RuntimeException', 'App\Jobs\FooJob', 'default', 'Boom');

    expect($group->isResolved())->toBeFalse()
        ->and($group->occurrences_count)->toBe(2);
});

it('relates monitors through the failure signature', function () {
    $group = FailureGroup::recordFailure('RuntimeException', 'App\Jobs\FooJob', 'default', 'Boom');

    QueueMonitor::create([
        'job_id' => 'uuid-1',
        'name' => 'App\Jobs\FooJob',
        'queue' => 'default',
        'started_at' => now(),
        'finished_at' => now(),
        'failed' => true,
        'attempt' => 1,
        'failure_signature' => $group->signature,
    ]);

    expect($group->monitors()->count())->toBe(1)
        ->and($group->dailyCounts(7))->toHaveCount(7)
        ->and($group->dailyCounts(7)[6])->toBe(1);
});

it('captures exception class, trace and signature when a monitored job fails', function () {
    $job = Mockery::mock(JobContract::class);
    $job->shouldReceive('payload')->andReturn(['uuid' => 'job-uuid-1']);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\FooJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);

    $started = new ReflectionMethod(QueueMonitorProvider::class, 'jobStarted');
    $started->invoke(null, $job);

    $finished = new ReflectionMethod(QueueMonitorProvider::class, 'jobFinished');
    $finished->invoke(null, $job, true, new RuntimeException('Boom for id 42'));

    $monitor = QueueMonitor::where('job_id', 'job-uuid-1')->firstOrFail();
    $group = FailureGroup::firstOrFail();

    expect($monitor->failed)->toBeTrue()
        ->and($monitor->exception_class)->toBe(RuntimeException::class)
        ->and($monitor->exception)->not->toBeEmpty()
        ->and($monitor->failure_signature)->toBe($group->signature)
        ->and($group->exception_class)->toBe(RuntimeException::class)
        ->and($group->job_class)->toBe('App\Jobs\FooJob')
        ->and($group->message)->toBe('Boom for id <num>')
        ->and($group->occurrences_count)->toBe(1);
});

it('counts a failure once when JobExceptionOccurred and JobFailed both fire', function () {
    $job = Mockery::mock(JobContract::class);
    $job->shouldReceive('payload')->andReturn(['uuid' => 'job-uuid-2']);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\FooJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);

    $started = new ReflectionMethod(QueueMonitorProvider::class, 'jobStarted');
    $started->invoke(null, $job);

    $finished = new ReflectionMethod(QueueMonitorProvider::class, 'jobFinished');
    // Simulates Laravel firing Queue::exceptionOccurred then Queue::failing.
    $finished->invoke(null, $job, true, new RuntimeException('Boom'));
    $finished->invoke(null, $job, true, new RuntimeException('Boom'));

    expect(FailureGroup::firstOrFail()->occurrences_count)->toBe(1);
});

it('renders the failure details view with stack trace, payload and occurrences', function () {
    $group = FailureGroup::recordFailure('RuntimeException', 'App\Jobs\FooJob', 'payments', 'Boom for id 42');

    $monitor = QueueMonitor::create([
        'job_id' => 'uuid-1',
        'name' => 'App\Jobs\FooJob',
        'queue' => 'payments',
        'started_at' => now(),
        'finished_at' => now(),
        'failed' => true,
        'attempt' => 2,
        'exception_message' => 'Boom for id 42',
        'exception' => "#0 /app/Jobs/FooJob.php(84): App\\Jobs\\FooJob->handle()\n#1 /var/www/vendor/laravel/framework/src/Illuminate/Queue/Job.php(98): Illuminate\\Queue\\CallQueuedHandler->call()\n#2 {main}",
        'failure_signature' => $group->signature,
    ]);

    $html = view('filament-jobs-monitor::failure-details', [
        'group' => $group,
        'lastOccurrence' => $monitor,
        'payload' => ['uuid' => 'uuid-1', 'data' => ['command' => 'O:12:"App\Jobs\Foo"', 'attempts' => 2]],
        'recentOccurrences' => collect([$monitor]),
    ])->render();

    expect($html)
        ->toContain('App\Jobs\FooJob-&gt;handle()')
        ->toContain('/app/Jobs/FooJob.php')
        ->toContain('uuid-1')
        ->toContain('payments');
});

it('renders the sparkline column view', function () {
    $group = FailureGroup::recordFailure('RuntimeException', 'App\Jobs\FooJob', 'default', 'Boom');

    $html = view('filament-jobs-monitor::columns.sparkline-column', [
        'getRecord' => fn () => $group,
    ])->render();

    expect($html)->toContain('<svg')->toContain('fjm-spark-'.$group->getKey());
});

it('computes the four failure stats', function () {
    FailureGroup::recordFailure('RuntimeException', 'App\Jobs\FooJob', 'default', 'Boom');

    QueueMonitor::create([
        'job_id' => 'uuid-1',
        'name' => 'App\Jobs\FooJob',
        'queue' => 'default',
        'started_at' => now(),
        'finished_at' => now(),
        'failed' => true,
        'attempt' => 1,
    ]);

    $stats = (new ReflectionMethod(FailureStatsOverview::class, 'getStats'))
        ->invoke(new FailureStatsOverview);

    expect($stats)->toHaveCount(4)
        ->and($stats[0]->getValue())->toBe('1')
        ->and($stats[1]->getValue())->toBe('1')
        ->and($stats[2]->getValue())->toBe('100%')
        ->and($stats[3]->getValue())->toBe('0');
});
