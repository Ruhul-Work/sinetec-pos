<?php
namespace App\Support;

class PermAbility
{
    public static function inferByRoute(string $routeName, string $method = 'GET'): string
    {
        if (preg_match('/\.(index|show|select2|quick)$/i', $routeName)) return 'view';
        if (preg_match('/\.(list|grid)(\.ajax)?$/i', $routeName))      return 'view';
        if (preg_match('/\.(create|createModal|store)$/i', $routeName)) return 'add';
        if (preg_match('/\.(edit|editModal|update)$/i', $routeName))    return 'edit';
        if (preg_match('/\.(destroy|delete)$/', $routeName))       return 'delete';
        if (str_contains($routeName, 'export'))                    return 'export';

        return match (strtoupper($method)) {
            'GET' => 'view', 'POST' => 'add',
            'PUT','PATCH' => 'edit', 'DELETE' => 'delete',
            default => 'view',
        };
    }
}
