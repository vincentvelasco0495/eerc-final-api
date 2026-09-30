<?php

namespace App\Services;

use App\Models\RolePagePermission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PagePermissionService
{
    /** @return Collection<int, RolePagePermission> */
    public function rulesForRole(string $role): Collection
    {
        $normalized = strtolower(trim($role));

        if ($normalized === '') {
            return collect();
        }

        return Cache::remember(
            "lms:page_permissions:{$normalized}",
            300,
            fn () => RolePagePermission::query()
                ->where('role', $normalized)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function clearCache(?string $role = null): void
    {
        if ($role !== null && trim($role) !== '') {
            Cache::forget('lms:page_permissions:'.strtolower(trim($role)));

            return;
        }

        foreach (['admin', 'instructor', 'student'] as $knownRole) {
            Cache::forget("lms:page_permissions:{$knownRole}");
        }
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    public function canAccess(string $role, string $pathname, array $queryParams = []): bool
    {
        $path = $this->normalizePath($pathname);

        foreach ($this->rulesForRole($role) as $rule) {
            if ($this->ruleMatches($rule, $path, $queryParams)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array<string, mixed>> */
    public function permissionsPayloadForRole(string $role): array
    {
        return $this->rulesForRole($role)
            ->map(fn (RolePagePermission $rule) => [
                'role' => $rule->role,
                'path' => $rule->path,
                'matchType' => $rule->match_type,
                'query' => $rule->query,
                'label' => $rule->label,
            ])
            ->values()
            ->all();
    }

    public function defaultHomePath(string $role): string
    {
        $normalized = strtolower(trim($role));

        return match ($normalized) {
            'student' => '/available-programs',
            'admin' => '/dashboard',
            'instructor' => '/instructor-home',
            default => '/available-programs',
        };
    }

    protected function normalizePath(string $pathname): string
    {
        $path = trim($pathname);

        if ($path === '') {
            return '/';
        }

        $path = '/'.ltrim($path, '/');

        return rtrim($path, '/') ?: '/';
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    protected function ruleMatches(RolePagePermission $rule, string $path, array $queryParams): bool
    {
        if (! $this->queryMatches($rule->query, $queryParams)) {
            return false;
        }

        $rulePath = $this->normalizePath($rule->path);

        return match ($rule->match_type) {
            'prefix' => $path === $rulePath || str_starts_with($path, $rulePath.'/'),
            'path_pattern' => (bool) preg_match($this->patternToRegex($rulePath), $path),
            default => $path === $rulePath,
        };
    }

    /**
     * @param  array<string, mixed>|null  $ruleQuery
     * @param  array<string, mixed>  $queryParams
     */
    protected function queryMatches(?array $ruleQuery, array $queryParams): bool
    {
        if ($ruleQuery === null || $ruleQuery === []) {
            return true;
        }

        foreach ($ruleQuery as $key => $expected) {
            $actual = $queryParams[$key] ?? null;

            if ((string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    protected function patternToRegex(string $pattern): string
    {
        $escaped = preg_quote($this->normalizePath($pattern), '#');
        $regex = preg_replace('/\\\\\*/', '[^/]+', $escaped);

        return '#^'.$regex.'$#';
    }
}
