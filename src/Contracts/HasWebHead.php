<?php

namespace Native\Mobile\Edge\Web\Contracts;

/**
 * A screen that contributes markup to the web page's <head>: meta
 * description, Open Graph / Twitter cards, canonical, JSON-LD. Emitted
 * raw after <title> on the full-page GET only (SPA navigations swap the
 * body and title, not the head), so it is the hook for anything a
 * crawler must see on a public, indexable screen.
 */
interface HasWebHead
{
    public function webHead(): string;
}
