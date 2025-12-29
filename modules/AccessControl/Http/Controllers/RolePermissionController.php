<?php

namespace Modules\AccessControl\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\AccessControl\Models\Role;

class RolePermissionController extends Controller
{
    public function assignRoleToUser(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $userModel = config('auth.providers.users.model');

        $user = $userModel::findOrFail($data['user_id']);
        $role = Role::findOrFail($data['role_id']);

        if (method_exists($user, 'assignRole')) {
            $user->assignRole($role);
        } else {
            $user->roles()->syncWithoutDetaching($role);
        }

        $user->load('roles');

        return response()->json([
            'message' => 'Role assigned to user successfully.',
            'data' => $user,
        ]);
    }
}
