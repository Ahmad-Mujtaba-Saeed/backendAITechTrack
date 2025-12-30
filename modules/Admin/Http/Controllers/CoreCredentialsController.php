<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Admin\Models\CoreCredential;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

class CoreCredentialsController extends Controller
{
    /**
     * List all core credentials (optionally filter by group)
     */
    public function index(Request $request)
    {
        $group = $request->query('group');

        $query = CoreCredential::query();

        if ($group) {
            $query->where('group', $group);
        }

        $credentials = $query->get()->map(function($item) {
            if ($item->is_encrypted) {
                $item->value = '******'; // hide actual value
            }
            return $item;
        });

        return response()->json($credentials);
    }

    /**
     * Store or update a core credential
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|string',
            'value' => 'required|string',
            'group' => 'required|string',
            'is_encrypted' => 'boolean',
            'type' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $isEncrypted = $request->get('is_encrypted', false);

        $credential = CoreCredential::updateOrCreate(
            ['key' => $request->key],
            [
                'value' => $request->value,
                'group' => $request->group,
                'is_encrypted' => $isEncrypted,
                'type' => $request->type
            ]
        );

        return response()->json([
            'status' => 'success',
            'data' => $credential
        ]);
    }

    /**
     * Get a single credential by key
     */
    public function show($key)
    {
        $credential = CoreCredential::where('key', $key)->firstOrFail();

        if ($credential->is_encrypted) {
            $credential->value = '******'; // hide actual value
        }

        return response()->json($credential);
    }

    /**
     * Delete a credential
     */
    public function destroy($key)
    {
        $credential = CoreCredential::where('key', $key)->firstOrFail();
        $credential->delete();

        return response()->json([
            'status' => 'success',
            'message' => "Credential '{$key}' deleted."
        ]);
    }
}
