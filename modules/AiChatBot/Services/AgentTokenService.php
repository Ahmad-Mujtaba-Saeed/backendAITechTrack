<?php

namespace Modules\AiChatBot\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class AgentTokenService
{
    public static function issue(
        int $userId,
        array $scopes = [],
        int $ttlMinutes = 5
    ): string {
        $token = 'agent_' . Str::random(48);

        Cache::put(
            "agent_token:{$token}",
            [
                'user_id' => $userId,
                'scopes' => $scopes,
                'issued_at' => now()->toISOString(),
            ],
            now()->addMinutes($ttlMinutes)
        );

        return $token;
    }
}
