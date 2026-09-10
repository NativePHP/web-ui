<?php

/**
 * EDGE live-reload server — the browser's half of `native:watch`.
 *
 * A phone can't see your working copy, so the watch command pushes files
 * to it and pokes it to reload. A browser can't be pushed to at all: HTTP
 * only answers questions it was asked. This process is the answer — one
 * WebSocket every open tab holds, and a broadcast whenever a watched file
 * changes. No polling, no request per tab per second.
 *
 * Standalone (not an artisan command) for the same reason Jump's bridge
 * is: Workerman takes over the process and parses $argv itself, which an
 * artisan command can't give it. Spawned in the background by
 * `edge:watch` — and by `native:watch` when this package is installed —
 * via Native\Mobile\Edge\Web\Server\ReloadServer.
 *
 * Usage:
 *   php reload-server.php <base_path> [port] [host] start
 *
 * While it runs it keeps storage/framework/edge-hot.json current:
 *
 *   {"hb": <ms>, "ws": <port>, "pid": <pid>, "ts": <ms>, "file": "..."}
 *
 *   hb        heartbeat, rewritten every few seconds. Its freshness is
 *             what tells the served page that live updates are available
 *             at all — stop watching and pages stop arming the client.
 *   ws, pid   where to connect, and who to stop.
 *   ts, file  the last change. Deliberately a different key from `hb`:
 *             the HTTP fallback derives its change token from `ts`, so a
 *             heartbeat must not look like a change.
 */

// ── Arguments ───────────────────────────────────────────────────────────

$positional = [];

foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['start', 'stop', 'restart', '-d', '-g'], true)) {
        continue;
    }

    $positional[] = $arg;
}

$basePath = $positional[0] ?? getenv('EDGE_HOT_BASE_PATH');
$port = (int) ($positional[1] ?? getenv('EDGE_HOT_PORT') ?: 3010);
$host = $positional[2] ?? (getenv('EDGE_HOT_HOST') ?: '127.0.0.1');

if (! $basePath || ! file_exists($basePath.'/vendor/autoload.php')) {
    fwrite(STDERR, "[edge] base_path not provided, or vendor/autoload.php missing\n");
    exit(1);
}

require_once $basePath.'/vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Native\Mobile\Edge\Web\Protocol\EdgeHot;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

// Boot the app ONCE, purely to resolve configured watch paths and the
// storage path. Nothing below touches the container again — a long-lived
// process holding a booted app would drift from the config on disk.
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$watchPaths = array_values(array_filter(EdgeHot::watchPaths(), 'is_dir'));
$statePath = $basePath.'/storage/framework/'.basename(EdgeHot::SIGNAL_FILE);
$stateDir = dirname($statePath);

if (! is_dir($stateDir)) {
    @mkdir($stateDir, 0755, true);
}

// ── State file ──────────────────────────────────────────────────────────

/** Merge keys into the state file, preserving the ones we don't touch. */
$writeState = function (array $merge) use ($statePath) {
    $current = [];

    if (is_file($statePath)) {
        $decoded = json_decode((string) @file_get_contents($statePath), true);
        $current = is_array($decoded) ? $decoded : [];
    }

    @file_put_contents($statePath, json_encode([...$current, ...$merge]));
};

$now = fn () => (int) round(microtime(true) * 1000);

// ── Server ──────────────────────────────────────────────────────────────

// Pin the pid file next to the state file: it is how the worker learns
// the MASTER's pid (see edgeMasterPid) and how a stale server can be
// found and stopped by hand. Workerman's default lives in the system
// temp dir under a hashed name — no use to anyone.
Worker::$pidFile = $stateDir.'/edge-hot.pid';

$worker = new Worker("websocket://{$host}:{$port}");
$worker->count = 1;
$worker->name = 'EdgeReload';

/** @var array<int, TcpConnection> */
$clients = [];

$worker->onConnect = function (TcpConnection $connection) use (&$clients) {
    $clients[$connection->id] = $connection;
    edgeLog('browser connected (total: '.count($clients).')');
};

$worker->onClose = function (TcpConnection $connection) use (&$clients) {
    unset($clients[$connection->id]);
    edgeLog('browser disconnected (remaining: '.count($clients).')');
};

// Nothing is accepted FROM a browser: this is a one-way notification
// channel. Reading its messages would make it an attack surface for
// anything on the machine that can open a socket, for no gain.
$worker->onMessage = function () {};

$worker->onWorkerStart = function () use (&$clients, $watchPaths, $writeState, $now, $port) {
    $writeState(['hb' => $now(), 'ws' => $port, 'pid' => edgeMasterPid()]);

    edgeLog('listening on '.$port.', watching '.count($watchPaths).' path(s)');

    // Heartbeat. Comfortably inside EdgeHot::HEARTBEAT_TTL so a page
    // rendered between beats still sees a live server.
    Timer::add(3, function () use ($writeState, $now, $port) {
        // Orphaned (master gone, reparented to init): stop. A worker that
        // outlives its master is a live-reload server nobody can address
        // — it holds the port and keeps re-publishing a heartbeat, so
        // pages connect to a watcher that no longer exists.
        if (function_exists('posix_getppid') && posix_getppid() === 1) {
            edgeLog('master gone — stopping');
            Worker::stopAll();

            return;
        }

        $writeState(['hb' => $now(), 'ws' => $port, 'pid' => edgeMasterPid()]);
    });

    // Change detection. Same shape as the HTTP fallback's fingerprint
    // (newest mtime + file count, traffic-tracking files ignored), except
    // it remembers the previous answer instead of handing it out.
    $previous = null;

    Timer::add(0.5, function () use (&$clients, &$previous, $watchPaths, $writeState, $now) {
        $newest = 0;
        $count = 0;
        $newestFile = null;

        foreach ($watchPaths as $path) {
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($iterator as $file) {
                    if (! $file->isFile() || EdgeHot::ignores($file->getFilename())) {
                        continue;
                    }

                    $count++;
                    $mtime = $file->getMTime();

                    if ($mtime > $newest) {
                        $newest = $mtime;
                        $newestFile = $file->getPathname();
                    }
                }
            } catch (Throwable) {
                // Mid-scan churn (a directory being rewritten by a build)
                // — the next tick sees the settled tree.
                continue;
            }
        }

        $fingerprint = $newest.'.'.$count;

        // First tick establishes the baseline; it is not a change.
        if ($previous === null) {
            $previous = $fingerprint;

            return;
        }

        if ($fingerprint === $previous) {
            return;
        }

        $previous = $fingerprint;

        $relative = $newestFile === null
            ? null
            : ltrim(str_replace(base_path(), '', $newestFile), '/\\');

        // Recorded for the HTTP fallback (a tab that couldn't open a
        // socket still needs the change to be visible somewhere) …
        $writeState(['ts' => $now(), 'file' => $relative]);

        // … and pushed to everyone holding one.
        $payload = json_encode(['type' => 'reload', 'file' => $relative]);

        foreach ($clients as $client) {
            $client->send($payload);
        }

        edgeLog('reload · '.($relative ?? 'change').' → '.count($clients).' browser(s)');
    });
};

// ── Shutdown ────────────────────────────────────────────────────────────

// Drop the heartbeat immediately on the way out: a stale file would keep
// pages arming a client for a server that is no longer there (they would
// recover on TTL, but a tab opened in that window points at nothing).
$clearState = function () use ($statePath, $writeState) {
    if (is_file($statePath)) {
        $writeState(['hb' => 0, 'ws' => null, 'pid' => null]);
    }
};

Worker::$onMasterStop = $clearState;

foreach ([SIGINT, SIGTERM] as $signal) {
    if (function_exists('pcntl_signal')) {
        pcntl_signal($signal, function () use ($clearState) {
            $clearState();
            Worker::stopAll();
        });
    }
}

function edgeLog(string $message): void
{
    fwrite(STDERR, '['.date('H:i:s').'] [edge] '.$message."\n");
}

/**
 * The pid a caller must signal to actually stop this server.
 *
 * Deliberately NOT getmypid(): Workerman forks, and the code that
 * publishes this runs in the WORKER. Signalling a worker just gets it
 * respawned by the master — the server would look unstoppable.
 *
 * The master writes its own pid to Worker::$pidFile at startup, which is
 * the only public way to learn it from inside a worker (Workerman 4 keeps
 * the value itself in a protected static). Falling back to the parent
 * process covers the moment before that file lands.
 */
function edgeMasterPid(): int
{
    $pid = (int) @file_get_contents(Worker::$pidFile);

    if ($pid > 0) {
        return $pid;
    }

    return function_exists('posix_getppid') ? posix_getppid() : getmypid();
}

Worker::runAll();
