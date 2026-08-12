<?php

namespace Modules\AiChatBot\Http\Controllers\Internal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Routing\Controller;

class AgentTokenController extends Controller
{
    public function validate(Request $request)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'Token missing'], 401);
        }

        $data = Cache::get("agent_token:{$token}");

        if (!$data) {
            return response()->json(['error' => 'Token invalid or expired'], 401);
        }

        return response()->json($data);
    }
}
