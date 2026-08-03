<?php

namespace Native\Mobile\Edge\Web;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Native\Mobile\Edge\Contracts\NativeRouteFallback;
use Native\Mobile\Edge\Web\Replay\ReplayViewer;
use Native\Mobile\Edge\Web\Bridge\WebBridge;
use Native\Mobile\Edge\Web\Protocol\EdgeEndpoint;
use Native\Mobile\Edge\Web\Protocol\EdgeUpload;
use Native\Mobile\Edge\Web\Protocol\WebScreenRunner;

/**
 * Everything the web render target adds to the app: the fallback
 * binding core dispatches through, the WebBridge, and the routes the
 * edge-web.js runtime talks to. Self-disables on device.
 *
 * This provider is the package-split seam made literal: when the web
 * feature moves to its own composer package, this file moves with it and
 * gets auto-discovered there — core keeps only the NativeRouteFallback contract
 * and stops registering this provider itself.
 */
class WebServiceProvider extends ServiceProvider
{
    /** A native runtime means no web rendering — every hook below is off-device only. */
    protected function offDevice(): bool
    {
        return ! env('NATIVEPHP_RUNNING') && ! config('nativephp-internal.running');
    }

    public function register(): void
    {
        if (! $this->offDevice()) {
            return;
        }

        // A browser GET on a native route resolves this contract — the
        // web target IS the app's fallback for off-runtime requests.
        $this->app->singleton(NativeRouteFallback::class, WebScreenRunner::class);

        // Web bridge: resolving WebBridge from the container yields the
        // per-request instance (enabling one if the screen runner hasn't
        // yet), so app drivers can queue effects or inspect calls via
        // app(WebBridge::class). enable() later swaps in the concrete
        // instance binding for the rest of the request.
        $this->app->bind(
            WebBridge::class,
            fn () => WebBridge::current() ?? WebBridge::enable(),
        );
    }

    public function boot(): void
    {
        if (! $this->offDevice()) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->commands([Console\EdgeCssCommand::class]);
        }

        // Per-installation paths (APP_KEY-derived, Livewire v4-style):
        // a unique prefix per app instead of a well-known endpoint, so
        // universal scanners can't target the update route. The page
        // embeds the real path in #edge-state for the client runtime.
        $edgePrefix = EdgeEndpoint::prefix();

        Route::post($edgePrefix.'/update', [WebScreenRunner::class, 'update'])
            ->middleware('web')
            ->name('edge.web.update');

        // Temporary file uploads (Livewire-style): multipart POST that
        // stores to storage/app/edge-tmp and returns HMAC-signed paths
        // consumable via EdgeUpload::validatePath(). CSRF via `web`.
        Route::post($edgePrefix.'/upload', [EdgeUpload::class, 'store'])
            ->middleware('web')
            ->name('edge.web.upload');

        // Serve the app's bundled font files (resources/fonts) so the
        // generated @font-face rules resolve — same faces as native.
        Route::get($edgePrefix.'/fonts/{file}', function (string $file) {
            $path = resource_path('fonts/'.basename($file));

            abort_unless(is_file($path) && preg_match('/\.(ttf|otf|woff2?)$/i', $path), 404);

            return response()->file($path, [
                'Content-Type' => match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                    'otf' => 'font/otf',
                    'woff' => 'font/woff',
                    'woff2' => 'font/woff2',
                    default => 'font/ttf',
                },
                'Cache-Control' => 'public, max-age=86400',
            ]);
        })->where('file', '[^/]+')->name('edge.web.font');

        // POC time-travel replay viewer for recorded sessions.
        Route::get($edgePrefix.'/replay', [ReplayViewer::class, 'index'])
            ->middleware('web')->name('edge.replay.index');
        Route::get($edgePrefix.'/replay/{name}', [ReplayViewer::class, 'show'])
            ->where('name', '[A-Za-z0-9]+')->middleware('web')->name('edge.replay.show');
    }
}
