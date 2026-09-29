<?php

/*
|--------------------------------------------------------------------------
| Queue
|--------------------------------------------------------------------------
|
| Only the `database` connection is overridden; every other key comes from
| the framework's default config/queue.php (Laravel 11 merges them).
|
| retry_after MUST exceed the longest job $timeout in app/Jobs (currently
| 600s: SyncEntraClientJob, ProvisionSuperOpsScimUsersJob,
| RepairSuperOpsScimExportJob). With the framework default of 90s, a
| long-running job's row is treated as abandoned after 90s and the next
| minute's `queue:work` picks the same job up again while the first worker
| is still running it: jobs with $tries = 1 are then failed mid-run
| ("attempted too many times"), clearing their in-flight flags and unique
| locks, and jobs with retries run twice in parallel against Graph.
| tests/Unit/QueueRetryAfterTest.php guards this invariant.
|
*/

return [

    'connections' => [

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 900),
            'after_commit' => false,
        ],

    ],

];
