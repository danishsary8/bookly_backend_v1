<?php

namespace App\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Auth;
use App\Helpers\AuthSession;
use App\Helpers\Request;
use App\Helpers\Validator;

class AuthController
{
    public function me(): void
    {
        $identity = Auth::identity();
        ApiResponse::success([
            'data' => [
                'id' => $identity['id'],
                'email' => $identity['email'],
                'role' => $identity['role'],
                'first_name' => $identity['first_name'],
                'last_name' => $identity['last_name'],
                'phone' => $identity['phone'],
                'address' => $identity['address'],
                'name' => $identity['name'],
            ],
        ]);
    }

    public function refresh(): void
    {
        $data = Request::jsonBody();
        Validator::requireFields($data, ['refresh_token']);
        $tokens = AuthSession::refreshAccessToken((string)$data['refresh_token']);

        ApiResponse::success([
            'data' => $tokens,
        ]);
    }

    public function logout(): void
    {
        $identity = Auth::identity();
        $data = Request::jsonBody();
        Validator::requireFields($data, ['refresh_token']);
        AuthSession::revokeRefreshToken((string)$data['refresh_token'], $identity);

        ApiResponse::success([
            'message' => 'Logged out successfully',
        ]);
    }
}
