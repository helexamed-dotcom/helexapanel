<?php
declare(strict_types=1);

namespace HeleXa\Middleware;

use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\View;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * Turns a silently discarded request body into an accurate message.
 *
 * Runs before the CSRF check, because a POST that exceeded post_max_size has
 * no token left to check and would otherwise be reported as an expired form.
 */
final class PostSizeMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!$request->exceededPostLimit()) {
            return $next($request);
        }

        $sent  = $request->contentLength();
        $limit = Request::bytesFromIni('post_max_size');

        ActivityLogger::log('upload.post_too_large', Auth::id(), null, null, [
            'sent_bytes'  => $sent,
            'limit_bytes' => $limit,
            'path'        => $request->path(),
        ], 'warning', $request);

        if ($request->isAjax()) {
            return Response::json([
                'ok'    => false,
                'error' => 'POST_TOO_LARGE',
                'sent'  => $sent,
                'limit' => $limit,
            ], 413);
        }

        return Response::html(View::page('layouts.error', 'errors.post_too_large', [
            'title'      => 'حجم درخواست بیش از حد مجاز',
            'sentMb'     => $sent > 0 ? round($sent / 1048576, 1) : 0,
            'limitMb'    => $limit > 0 ? round($limit / 1048576, 1) : 0,
            'uploadMb'   => round(Request::bytesFromIni('upload_max_filesize') / 1048576, 1),
            'inputVars'  => (string) ini_get('max_input_vars'),
        ]), 413);
    }
}
