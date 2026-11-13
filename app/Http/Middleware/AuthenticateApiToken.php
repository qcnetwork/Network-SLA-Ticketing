<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For other applications using this one's data: they send a token as "Authorization: Bearer <token>".
 * CUSTOMER_API_TOKENS may only read; CUSTOMER_API_WRITE_TOKENS may also write (the FTTH mapping app).
 * Tokens shorter than 32 characters are ignored, so a placeholder or a guessable word never opens the API.
 *
 * Usage in routes: AuthenticateApiToken::class to read, AuthenticateApiToken::class.':write' to write.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $access = 'read'): Response
    {
        $given = (string) $request->bearerToken();
        $canWrite = $this->matches($given, config('services.customer_api.write_tokens'));
        $canRead = $canWrite || $this->matches($given, config('services.customer_api.tokens'));

        abort_unless($canRead, 401, 'Token API tidak valid');
        abort_if($access === 'write' && ! $canWrite, 403, 'Token ini hanya boleh membaca');

        return $next($request);
    }

    /** @param  list<string>  $tokens */
    private function matches(string $given, array $tokens): bool
    {
        return $given !== '' && collect($tokens)
            ->filter(fn (string $token) => strlen($token) >= 32)
            ->contains(fn (string $token) => hash_equals($token, $given));
    }
}
