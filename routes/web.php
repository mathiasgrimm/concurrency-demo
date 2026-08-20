<?php

use Illuminate\Concurrency\TaskTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

// Browser navigations (Accept: text/html) receive the sidebar page, which
// auto-runs the demo for the current URL by fetching it again with
// Accept: application/json. Plain curl still gets JSON.
$demo = function (Closure $handler) {
    return function () use ($handler) {
        if (str_contains(request()->header('Accept', ''), 'text/html')) {
            return view('concurrency-demo');
        }

        return $handler();
    };
};

Route::get('/', fn () => view('concurrency-demo'));

// Returns the actual route code for a demo, sliced out of this file via
// reflection, so the page can display exactly what each demo runs.
Route::get('/demo-source', function () {
    $uri = ltrim(request()->query('path', ''), '/');

    abort_unless(str_starts_with($uri, 'demo'), 404);

    $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === $uri);

    abort_unless($route && $route->getAction('uses') instanceof Closure, 404);

    $reflection = new ReflectionFunction($route->getAction('uses'));

    if ($handler = $reflection->getStaticVariables()['handler'] ?? null) {
        $reflection = new ReflectionFunction($handler);
    }

    $lines = array_slice(
        file($reflection->getFileName()),
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    );

    $source = implode('', $lines);

    // Highlight with PHP's own tokenizer, using the page's palette.
    ini_set('highlight.comment', '#8a8880');
    ini_set('highlight.default', '#26251f');
    ini_set('highlight.html', '#8a8880');
    ini_set('highlight.keyword', '#d64541');
    ini_set('highlight.string', '#1a7f37');

    $html = preg_replace(
        '/&lt;\?php(<br \/>|\n)?/',
        '',
        highlight_string("<?php\n".$source, true),
        1,
    );

    return response()->json(['source' => $source, 'html' => $html]);
});

// The "synchronous endpoint" story: fan work out to queue workers and
// return the results in the same response. Run queue workers first.
Route::get('/demo', $demo(function () {
    $start = microtime(true);

    $results = Concurrency::driver('queue')->run([
        'thumbnail' => function () {
            sleep(2);

            return 'thumbnail done by worker pid '.getmypid();
        },
        'preview' => function () {
            sleep(2);

            return 'preview done by worker pid '.getmypid();
        },
        'optimized' => function () {
            sleep(2);

            return 'optimized done by worker pid '.getmypid();
        },
    ], timeout: 15);

    return response()->json([
        'connection' => 'redis (parallel, wall time close to the slowest task)',
        'caller_pid' => getmypid(),
        'wall_time_seconds' => round(microtime(true) - $start, 2),
        'results' => $results,
    ]);
}));

// Runtime targeting: the same tasks on the sync connection. Everything
// runs inline in this request, so no workers are needed, but the tasks
// run one after the other (compare the wall time with /demo).
Route::get('/demo-sync', $demo(function () {
    $start = microtime(true);

    $results = Concurrency::driver('queue')->onConnection('sync')->run([
        'thumbnail' => function () {
            sleep(2);

            return 'thumbnail done by pid '.getmypid();
        },
        'preview' => function () {
            sleep(2);

            return 'preview done by pid '.getmypid();
        },
        'optimized' => function () {
            sleep(2);

            return 'optimized done by pid '.getmypid();
        },
    ], timeout: 15);

    return response()->json([
        'connection' => 'sync (inline, wall time is the sum of the tasks)',
        'caller_pid' => getmypid(),
        'wall_time_seconds' => round(microtime(true) - $start, 2),
        'results' => $results,
    ]);
}));

// A task that throws on a queue worker: the exception is rebuilt and
// rethrown here in the caller, and the failed job is also visible in
// Horizon as a CapturedTaskException wrapping the original.
Route::get('/demo-exception', $demo(function () {
    try {
        Concurrency::driver('queue')->run([
            'ok' => fn () => 'this task succeeds',
            'boom' => fn () => throw new RuntimeException('Image conversion failed on the worker'),
        ], timeout: 15);
    } catch (Throwable $e) {
        return response()->json([
            'connection' => 'redis',
            'caught_in_caller_pid' => getmypid(),
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'note' => 'The failure is also recorded in Horizon under failed jobs.',
        ], 500);
    }

    return response()->json(['error' => 'expected an exception to be thrown'], 500);
}));

// The same failing task on the sync connection: no worker involved and
// nothing is recorded as a failed job, the exception simply comes back.
Route::get('/demo-exception-sync', $demo(function () {
    try {
        Concurrency::driver('queue')->onConnection('sync')->run([
            'ok' => fn () => 'this task succeeds',
            'boom' => fn () => throw new RuntimeException('Image conversion failed inline'),
        ], timeout: 15);
    } catch (Throwable $e) {
        return response()->json([
            'connection' => 'sync',
            'caught_in_caller_pid' => getmypid(),
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ], 500);
    }

    return response()->json(['error' => 'expected an exception to be thrown'], 500);
}));

// What a timeout looks like: the tasks go to a queue no worker consumes
// (locally nothing listens to it; on Laravel Cloud it is a managed queue
// that exists but is paused), so after 3 seconds the caller gets a
// TaskTimedOutException with the full diagnostic message. A cancellation
// flag is written, so even if a worker consumes that queue later, the
// jobs will refuse to run.
Route::get('/demo-timeout', $demo(function () {
    try {
        Concurrency::driver('queue')->onQueue('nobody-listens')->run([
            fn () => 'never collected',
            fn () => 'never collected',
        ], timeout: 3);
    } catch (TaskTimedOutException $e) {
        rescue(fn () => Queue::connection()->clear('nobody-listens'), report: false);

        return response()->json([
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'received' => $e->received,
            'total' => $e->total,
            'note' => 'The cancellation flag was written, so these jobs would refuse to run even if a worker picked them up later.',
        ], 500);
    }

    return response()->json(['error' => 'expected a timeout'], 500);
}));

// The other half of the contract: defer() is fire and forget. The
// response returns immediately and the tasks are dispatched to the
// queue after it is sent. Check /demo-defer-status afterwards.
Route::get('/demo-defer', $demo(function () {
    Concurrency::driver('queue')->defer([
        function () {
            sleep(2);

            Cache::put('demo:defer:one', 'ran at '.now()->toDateTimeString().' on pid '.getmypid(), 300);
        },
        function () {
            sleep(2);

            Cache::put('demo:defer:two', 'ran at '.now()->toDateTimeString().' on pid '.getmypid(), 300);
        },
    ]);

    return response()->json([
        'dispatched' => 'after this response is sent',
        'note' => 'Requires Horizon running. Results are never collected with defer(); these tasks write cache markers instead. Each sleeps 2s, so with two or more workers the markers usually show two different pids. Check /demo-defer-status after a few seconds.',
    ]);
}));

Route::get('/demo-defer-status', $demo(function () {
    return response()->json([
        'one' => Cache::get('demo:defer:one', 'not run yet'),
        'two' => Cache::get('demo:defer:two', 'not run yet'),
    ]);
}));

// Same tasks, three drivers, one contract: sequential inline, parallel
// local processes, and parallel queue workers. Compare the wall times.
Route::get('/demo-benchmark', $demo(function () {
    $tasks = fn () => [
        'resize' => function () {
            sleep(2);

            return 'pid '.getmypid();
        },
        'optimize' => function () {
            sleep(2);

            return 'pid '.getmypid();
        },
        'watermark' => function () {
            sleep(2);

            return 'pid '.getmypid();
        },
    ];

    $benchmark = [];

    foreach (['sync', 'process', 'queue'] as $driver) {
        $start = microtime(true);

        try {
            $results = Concurrency::driver($driver)->run($tasks(), timeout: 10);

            $benchmark[$driver] = [
                'wall_time_seconds' => round(microtime(true) - $start, 2),
                'results' => $results,
            ];
        } catch (TaskTimedOutException $e) {
            $benchmark[$driver] = [
                'wall_time_seconds' => round(microtime(true) - $start, 2),
                'error' => 'Timed out. Is Horizon running?',
            ];
        }
    }

    return response()->json([
        'caller_pid' => getmypid(),
        'tasks' => 'three tasks of 2 seconds each',
        'benchmark' => $benchmark,
    ]);
}));
