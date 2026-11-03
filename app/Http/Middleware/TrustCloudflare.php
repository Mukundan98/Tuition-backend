<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustCloudflare
{
    /**
     * Handle an incoming request.
     *
     * In Cloudflare environments, Cloudflare sets `CF-Connecting-IP`
     * to the real client IP, and passes `X-Forwarded-Proto` for SSL termination.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $cfIp = $request->header('cf-connecting-ip');
        if ($cfIp) {
            $request->server->set('REMOTE_ADDR', $cfIp);
        }

        // If Cloudflare terminated HTTPS, ensure request recognizes HTTPS
        $cfVisitor = $request->header('cf-visitor');
        if ($cfVisitor && str_contains($cfVisitor, '"scheme":"https"')) {
            $request->server->set('HTTPS', 'on');
            $request->server->set('SERVER_PORT', 443);
        } elseif ($request->header('x-forwarded-proto') === 'https') {
            $request->server->set('HTTPS', 'on');
            $request->server->set('SERVER_PORT', 443);
        }

        return $next($request);
    }
}
