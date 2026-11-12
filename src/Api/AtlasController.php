<?php

namespace ErnestDefoe\Hangout\Api;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/hangout/art?v={hash}: the art atlas, one WebP image. Served from
 * the extension's own folder (no publish step to forget after an update) and
 * cached for a year: the version in the URL changes with the file.
 * 🚨 The route must not end in .webp: nginx would serve it from disk.
 */
class AtlasController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $file = __DIR__.'/../../assets/atlas.webp';

        return (new Response(new Stream($file, 'r'), 200))
            ->withHeader('Content-Type', 'image/webp')
            ->withHeader('Content-Length', (string) filesize($file))
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
