<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SubscribeAccessService
{
    public const MODE = 3;
    public const DEFAULT_MINUTES = 10;
    public const DEFAULT_USER_AGENT = 'VcNet/v\d+(\.\d+){2}(?![\d.])';

    public static function required(): bool
    {
        return (int)config('v2board.show_subscribe_method', 0) === self::MODE;
    }

    public function status(User $user): array
    {
        $required = self::required();
        $expires = $required ? (int)Cache::store('redis')->get($this->key($user), 0) : 0;
        $remaining = max(0, $expires - time());

        return [
            'manual_required' => $required,
            'active' => !$required || $remaining > 0,
            'expires_at' => $remaining > 0 ? $expires : null,
            'remaining_seconds' => $remaining,
        ];
    }

    public function enable(User $user): array
    {
        $minutes = (int)config('v2board.subscribe_manual_minutes', self::DEFAULT_MINUTES);
        $seconds = max(1, min(1440, $minutes)) * 60;
        $cache = Cache::store('redis');
        $key = $this->key($user);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $cache->add($key, time() + $seconds, $seconds);
            $status = $this->status($user);
            if ($status['active']) {
                return $status;
            }
        }

        abort(503, __('Unable to enable subscription access. Please try again later.'));
    }

    public function allows(User $user, ?string $userAgent): bool
    {
        if (!self::required()) {
            return true;
        }

        $expressions = (string)config('v2board.subscribe_manual_user_agent', self::DEFAULT_USER_AGENT);
        return self::matchesUserAgent($userAgent, $expressions) || $this->status($user)['active'];
    }

    public static function matchesUserAgent(?string $userAgent, string $expressions): bool
    {
        if ($userAgent === null || $userAgent === '' || strlen($userAgent) > 2048) {
            return false;
        }

        foreach (self::userAgentExpressions($expressions) as $expression) {
            $pattern = self::userAgentPattern($expression);
            if ($pattern !== null && @preg_match($pattern, $userAgent) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function userAgentExpressions(string $value): array
    {
        return array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $value)), function ($expression) {
            return $expression !== '';
        });
    }

    public static function userAgentPattern(string $expression): ?string
    {
        if ($expression === '') {
            return null;
        }

        foreach (['~', '#', '%', '!'] as $delimiter) {
            if (strpos($expression, $delimiter) === false) {
                return $delimiter . '(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=1000)(?:' . $expression . ')' . $delimiter . 'i';
            }
        }

        return null;
    }

    private function key(User $user): string
    {
        return 'SUBSCRIBE_MANUAL:' . $user->id . ':' . hash('sha256', $user->token);
    }
}
