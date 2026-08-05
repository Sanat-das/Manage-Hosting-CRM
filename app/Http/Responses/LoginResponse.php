<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as Contract;

/**
 * Fortify login response that redirects users to the correct
 * dashboard based on their role, while respecting the
 * "intended" URL when it belongs to the same role area.
 */
class LoginResponse implements Contract
{
    public function toResponse($request)
    {
        $user = $request->user();
        $isClient = $user->hasRole('client');
        $home = $isClient ? route('client.dashboard') : route('admin.dashboard');

        $intended = $request->session()->get('url.intended');

        if ($intended && $this->isAllowedIntended($intended, $isClient)) {
            return redirect()->intended($home);
        }

        return redirect($home);
    }

    private function isAllowedIntended(string $url, bool $isClient): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($isClient) {
            return ! str_starts_with($path, '/admin');
        }

        return str_starts_with($path, '/admin');
    }
}
