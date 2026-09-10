<?php

namespace Native\Mobile\Edge\Web\Server;

use Native\Mobile\Edge\Web\Protocol\EdgeHot;
use Symfony\Component\Process\Process;

/**
 * Starts and stops the live-reload WebSocket server
 * (resources/hot/reload-server.php) as a background process.
 *
 * Nothing here is long-lived: the server owns its own lifetime and
 * publishes it through the state file, so this class only ever spawns,
 * signals, and reads. That means a crashed watcher can't leave a caller
 * believing the server is up — liveness is always the heartbeat's answer,
 * never a variable someone set.
 */
class ReloadServer
{
    /** Ports tried in order when none is configured. */
    protected const DEFAULT_PORT = 3010;

    protected const PORT_ATTEMPTS = 20;

    /**
     * The spawned process, held for the lifetime of the command that
     * started it. NOT bookkeeping: Symfony's Process destructor stops the
     * child, so dropping this reference kills the server a few
     * milliseconds after starting it — long enough to publish one
     * heartbeat and look like it worked.
     */
    protected static ?Process $process = null;

    /** Whether a server is currently publishing a fresh heartbeat. */
    public static function isRunning(): bool
    {
        return EdgeHot::watching();
    }

    /**
     * Spawn the server unless one is already up. Returns the port it is
     * reachable on, or null when it could not be started (in which case
     * the HTTP fallback carries live updates on its own).
     */
    public static function start(): ?int
    {
        if (static::isRunning()) {
            return EdgeHot::wsPort();
        }

        $script = realpath(__DIR__.'/../../resources/hot/reload-server.php');

        if ($script === false) {
            return null;
        }

        $port = static::availablePort();

        if ($port === null) {
            return null;
        }

        $process = new Process(
            [PHP_BINARY, $script, base_path(), (string) $port, static::host(), 'start'],
            base_path(),
            null,
            null,
            null, // no timeout: it runs for as long as you're watching
        );

        $process->disableOutput();
        $process->start();

        static::$process = $process;

        // The state file is the handshake: the server writes its
        // heartbeat as its first act, so waiting for it means waiting for
        // a socket that actually accepts connections.
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            if (static::isRunning()) {
                return EdgeHot::wsPort();
            }

            usleep(100_000);
        }

        // Started but never announced itself — kill it rather than leave
        // an orphan holding a port.
        $process->stop(1);
        static::$process = null;

        return null;
    }

    /**
     * Stop the running server, if any. Safe to call when none is up.
     *
     * Verified rather than fire-and-forget: the pid is Workerman's
     * MASTER, and a master that ignores SIGTERM leaves a worker holding
     * the port and re-publishing the heartbeat — pages would keep
     * connecting to a watcher that isn't there. So we watch the port and
     * escalate if it stays open.
     */
    public static function stop(): void
    {
        $pid = EdgeHot::serverPid();
        $port = EdgeHot::wsPort();

        EdgeHot::clearServerState();
        static::$process = null;

        if ($pid === null) {
            return;
        }

        static::signal($pid, graceful: true);

        if ($port === null || static::portClosed($port, 3)) {
            return;
        }

        static::signal($pid, graceful: false);
        static::portClosed($port, 1);
    }

    protected static function signal(int $pid, bool $graceful): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            (new Process(array_filter(['taskkill', $graceful ? null : '/F', '/PID', (string) $pid])))->run();

            return;
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, $graceful ? SIGTERM : SIGKILL);

            return;
        }

        (new Process(['kill', $graceful ? '-TERM' : '-KILL', (string) $pid]))->run();
    }

    /** Wait up to $seconds for the port to stop accepting connections. */
    protected static function portClosed(int $port, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        do {
            $socket = @fsockopen(static::host(), $port, $errno, $errstr, 0.2);

            if ($socket === false) {
                return true;
            }

            fclose($socket);
            usleep(150_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * Loopback by default: the reload channel is for browsers on this
     * machine, and binding it wider hands anything on the network a
     * broadcast into your dev tabs. `nativephp-web.hot_host` opens it up
     * deliberately (viewing the site from a phone on the LAN).
     */
    public static function host(): string
    {
        return (string) config('nativephp-web.hot_host', '127.0.0.1');
    }

    /** First free port from the configured base. */
    protected static function availablePort(): ?int
    {
        $base = (int) config('nativephp-web.hot_port', static::DEFAULT_PORT);

        for ($port = $base; $port < $base + static::PORT_ATTEMPTS; $port++) {
            $socket = @fsockopen(static::host(), $port, $errno, $errstr, 0.2);

            if ($socket === false) {
                return $port; // nothing listening — it's ours
            }

            fclose($socket);
        }

        return null;
    }
}
