<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class AuthCheckingForToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['ok' => false, 'error' => 'Missing or invalid Authorization header.', 'status' => 401], 401);
        }

        $userToken = trim(substr($authHeader, 7));
        $cfg = config('services.auth_server');
        $url = rtrim($cfg['base_url'], '/') . '/' . ltrim($cfg['verify_path'], '/');

        // context keys for caching
        $routeName = $request->route()?->getName();
        $ctxKey    = $routeName ?: ($request->method() . ' ' . $request->getPathInfo());
        $cacheKey  = 'verify:v1:' . hash('sha256', $userToken) . ':' . md5($ctxKey) . ':' . ($cfg['service_name'] ?? 'svc');

        if ($cached = Cache::get($cacheKey)) {
            return $this->apply($cached, $request, $next);
        }

        $http = Http::acceptJson()
            ->timeout($cfg['timeout'])
            ->retry($cfg['retries'], $cfg['retry_ms'])
            ->withToken($cfg['call_token']);

        $payload = [
            'service'    => $cfg['service_name'],
            'token'      => $userToken,
            'method'     => $request->method(),
            'path'       => $request->getPathInfo(),
            'route_name' => $routeName,
        ];

        try {
            $resp = $http->post($url, $payload);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Auth service unreachable.',
                'detail' => config('app.debug') ? $e->getMessage() : null,
                'status' => 401,
            ], 401);
        }

        if (!$resp->ok()) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized.', 'status' => 401], 401);
        }

        $data = $resp->json();

        if (!($data['active'] ?? false)) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized (inactive token).', 'status' => 401], 401);
        }

        $authorized = (bool)($data['ext']['authorized'] ?? false);
        if (!$authorized) {
            $required = $data['ext']['required_permissions'] ?? [];
            return response()->json([
                'ok' => false,
                'error' => 'Forbidden (token not authorized for this route).',
                'required_permissions' => config('app.debug') ? $required : null,
                'status' => 403,
            ], 403);
        }

        $userArr = (array) ($data['user'] ?? []);
        if (!array_key_exists('id', $userArr)) {
            return response()->json(['ok' => false, 'error' => 'Auth payload missing user id.', 'status' => 401], 401);
        }

        // Sync user, roles, and permissions to local database
        $this->syncAuthData($userArr, $data['roles'] ?? [], $data['permissions'] ?? []);

        // Set the authenticated user from our local database
        $localUser = User::find($userArr['id']);
        Auth::setUser($localUser);

        // cache
        $ttl = max(1, (int) ($cfg['cache_ttl'] ?? 30));
        if (isset($data['exp']) && is_int($data['exp'])) {
            $secondsLeft = $data['exp'] - time();
            if ($secondsLeft > 0) $ttl = min($ttl, $secondsLeft);
        }
        Cache::put($cacheKey, $data, now()->addSeconds($ttl));

        return $next($request);
    }

    private function apply(array $data, Request $request, Closure $next): Response
    {
        if (!($data['active'] ?? false)) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized (inactive token).', 'status' => 401], 401);
        }

        $authorized = (bool)($data['ext']['authorized'] ?? false);
        if (!$authorized) {
            $required = $data['ext']['required_permissions'] ?? [];
            return response()->json([
                'ok' => false,
                'error' => 'Forbidden (token not authorized for this route).',
                'required_permissions' => config('app.debug') ? $required : null,
                'status' => 403,
            ], 403);
        }

        $userArr = (array) ($data['user'] ?? []);
        
        // Sync and set authenticated user
        $this->syncAuthData($userArr, $data['roles'] ?? [], $data['permissions'] ?? []);
        $localUser = User::find($userArr['id']);
        Auth::setUser($localUser);

        return $next($request);
    }

    /**
     * Sync user, roles, and permissions from auth system to local database
     */
    private function syncAuthData(array $userArr, array $rolesArr, array $permissionsArr): void
    {
        DB::transaction(function () use ($userArr, $rolesArr, $permissionsArr) {
            // 1. Sync or create user
            $user = User::updateOrCreate(
                ['id' => $userArr['id']],
                [
                    'name' => $userArr['name'] ?? '',
                    'email' => $userArr['email'] ?? '',
                ]
            );

            // 2. Sync roles
            $roleIds = [];
            foreach ($rolesArr as $roleData) {
                if (is_array($roleData) && isset($roleData['id'])) {
                    $role = Role::updateOrCreate(
                        ['id' => $roleData['id']],
                        [
                            'name' => $roleData['name'] ?? '',
                            'description' => $roleData['description'] ?? null,
                        ]
                    );
                    $roleIds[] = $role->id;
                }
            }

            // 3. Sync permissions
            $permissionIds = [];
            foreach ($permissionsArr as $permData) {
                if (is_array($permData) && isset($permData['id'])) {
                    $permission = Permission::updateOrCreate(
                        ['id' => $permData['id']],
                        [
                            'name' => $permData['name'] ?? '',
                            'description' => $permData['description'] ?? null,
                        ]
                    );
                    $permissionIds[] = $permission->id;
                }
            }

            // 4. Sync user's roles (remove old, add new)
            $user->roles()->sync($roleIds);

            // 5. Sync user's permissions (remove old, add new)
            $user->permissions()->sync($permissionIds);
        });
    }
}
