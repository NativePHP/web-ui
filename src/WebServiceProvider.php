<?php

namespace Native\Mobile\Edge\Web;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Native\Mobile\Edge\Contracts\NativeRouteFallback;
use Native\Mobile\Edge\Web\Bridge\WebBridge;
use Native\Mobile\Edge\Web\Protocol\EdgeEndpoint;
use Native\Mobile\Edge\Web\Protocol\EdgeUpload;
use Native\Mobile\Edge\Web\Protocol\WebScreenRunner;
use Native\Mobile\Edge\Web\Renderer\WebRenderer;
use Native\Mobile\Edge\Web\Replay\ReplayViewer;

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

        // Local-file image srcs → signed serving URLs (see the file
        // route below). Wired here so the renderer stays transport-free.
        WebRenderer::setLocalSrcResolver(
            fn (string $path) => EdgeUpload::fileUrl($path),
        );

        // The same Laravel disks core registers on device
        // (NativeServiceProvider::registerFilesystems, gated on the
        // native runtime), mapped to their web-sensible roots — so
        // `Storage::disk('mobile_public')` / `disk('temp')` is
        // target-identical author code. `temp` points at edge-tmp:
        // that's where picked/uploaded files land on this target, the
        // role the native tempdir plays on device. Both roots live
        // under storage/app, so `->path()` results render as images
        // via the signed file route.
        config([
            'filesystems.disks.mobile_public' => config('filesystems.disks.mobile_public', [
                'driver' => 'local',
                'root' => storage_path('app/public'),
                'url' => config('app.url').'/storage',
                'visibility' => 'public',
                'throw' => false,
                'report' => false,
            ]),
            'filesystems.disks.temp' => config('filesystems.disks.temp', [
                'driver' => 'local',
                'root' => storage_path('app/'.EdgeUpload::DIRECTORY),
                'throw' => false,
            ]),
        ]);

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

        // Serve signed local files (storage/app only): the web half of
        // `<native:image :src="$absolutePath">` — WebRenderer rewrites
        // local paths to these URLs so the same author code renders on
        // both targets. HMAC-gated; see EdgeUpload::fileUrl().
        Route::get($edgePrefix.'/file', function () {
            $path = (string) request()->query('p', '');
            $sig = (string) request()->query('s', '');

            $real = EdgeUpload::validateFileUrl($path, $sig);

            abort_if($real === null, 404);

            return response()->file($real, [
                'Cache-Control' => 'private, max-age=3600',
            ]);
        })->name('edge.web.file');

        // POC time-travel replay viewer for recorded sessions.
        Route::get($edgePrefix.'/replay', [ReplayViewer::class, 'index'])
            ->middleware('web')->name('edge.replay.index');
        Route::get($edgePrefix.'/replay/{name}', [ReplayViewer::class, 'show'])
            ->where('name', '[A-Za-z0-9]+')->middleware('web')->name('edge.replay.show');
    }
}
