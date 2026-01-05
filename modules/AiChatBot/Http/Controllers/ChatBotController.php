<?php

namespace Modules\AiChatBot\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AiChatBot\Services\AgentTokenService;

class ChatBotController extends Controller
{
    public function index()
    {
        return response()->json([
            'message' => 'AI ChatBot module is working',
            'status' => 'success',
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'thread_id' => 'required|string',
        ]);

        $user = auth()->user();

        $agentToken = AgentTokenService::issue(
            $user->id,
            scopes: ['chat.send', 'subscription.read']
        );

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post('http://host.docker.internal:8940/chat', [
                    'message' => $validated['message'],
                    'thread_id' => $validated['thread_id'],
                    'user_id' => $user->id ?? null,
                    'agent_token' => $agentToken,
                ]);


            if (!$response->successful()) {
                return response()->json([
                    'error' => 'AI service unavailable',
                    'agent_token' => $agentToken,
                    'details' => $response->json(),
                ], 500);
            }

            $responseData = $response->json();
            return response()->json([
                'message' => $responseData['message'] ?? 'Chat response received',
                'agent_token' => $agentToken,
                'user_id' => $user->id ?? null,
                'timestamp' => now()->toISOString(),
                'server' => 'ai-chatbot-api',
                'response_data' => $responseData,
                'status' => 'success',
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'error' => 'AI service call failed',
                'agent_token' => $agentToken,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'status' => 'error',
            ], 500);
        }
    }


    public function resume(Request $request)
    {
        $validated = $request->validate([
            'decision' => 'required|string',
            'thread_id' => 'required|string',
            'key' => 'required|string',
        ]);

        $user = auth()->user();

        $agentToken = AgentTokenService::issue(
            $user->id,
            scopes: ['chat.send', 'subscription.read']
        );

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post('http://host.docker.internal:8940/chat/resume', [
                    'decision' => $validated['decision'],
                    'thread_id' => $validated['thread_id'],
                    'key' => $validated['key'],
                    'user_id' => $user->id ?? null,
                    'agent_token' => $agentToken,
                ]);


            if (!$response->successful()) {
                return response()->json([
                    'error' => 'AI service unavailable',
                    'agent_token' => $agentToken,
                    'details' => $response->json(),
                ], 500);
            }

            $responseData = $response->json();
            return response()->json([
                'message' => $responseData['message'] ?? 'Chat response received',
                'agent_token' => $agentToken,
                'user_id' => $user->id ?? null,
                'timestamp' => now()->toISOString(),
                'server' => 'ai-chatbot-api',
                'response_data' => $responseData,
                'status' => 'success',
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'error' => 'AI service call failed',
                'agent_token' => $agentToken,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'status' => 'error',
            ], 500);
        }
    }
}
