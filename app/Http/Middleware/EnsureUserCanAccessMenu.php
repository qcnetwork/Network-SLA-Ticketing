<?php

namespace App\Http\Middleware;

use App\Enums\Menu;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage in routes: ->middleware('menu:archive'). Follows the role's menus plus any per-user
 * grants and revocations set on the access page.
 */
class EnsureUserCanAccessMenu
{
    public function handle(Request $request, Closure $next, string $menu): Response
    {
        abort_unless($request->user()?->canAccess(Menu::from($menu)), 403, 'Akses ke menu ini belum diberikan untuk akun Anda');

        return $next($request);
    }
}
