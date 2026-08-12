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
            'thread_id' => 'nullable|string',
            'code' => 'nullable|string',
            'mode' => 'nullable|string',
            'selections' => 'nullable|array',
        ]);

        $user = auth()->user();

        $agentToken = AgentTokenService::issue(
            $user->id,
            scopes: ['chat.send', 'subscription.manage','resume.manage']
        );

        try {
            Log::info('Sending request to AI service', [
                'endpoint' => 'http://host.docker.internal:8950/chat',
                'user_id' => $user->id ?? null,
                'thread_id' => $validated['thread_id'] ?? null,
                'mode' => $validated['mode'] ?? null,
                'selections' => $validated['selections'] ?? null,
                'selections_count' => is_array($validated['selections'] ?? null) ? count($validated['selections']) : 0,
                'selections_meta' => collect($validated['selections'] ?? [])->map(function ($s) {
                    return [
                        'filePath' => $s['filePath'] ?? null,
                        'fileName' => $s['fileName'] ?? null,
                        'lineRange' => $s['lineRange'] ?? null,
                        'startLine' => $s['startLine'] ?? null,
                        'endLine' => $s['endLine'] ?? null,
                        'label' => $s['label'] ?? null,
                    ];
                })->values()->all(),
            ]);

            $response = Http::timeout(120)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post('http://host.docker.internal:8950/chat', [
                    'message' => $validated['message'],
                    'thread_id' => $validated['thread_id'] ?? null,
                    'code' => $validated['code'] ?? null,
                    'mode' => $validated['mode'] ?? null,
                    'selections' => $validated['selections'] ?? [],
                    'user_id' => $user->id ?? null,
                    'agent_token' => $agentToken,
                ]);

            Log::info('AI service response received', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'has_actions' => (bool) (($response->json()['actions'] ?? null) && is_array($response->json()['actions'])),
                'actions_count' => is_array($response->json()['actions'] ?? null) ? count($response->json()['actions']) : 0,
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
            'thread_id' => 'required|string'
        ]);

        $user = auth()->user();

        $agentToken = AgentTokenService::issue(
            $user->id,
            scopes: ['chat.send', 'subscription.manage','resume.manage']
        );

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post('http://host.docker.internal:8950/chat/resume', [
                    'decision' => $validated['decision'],
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
}
