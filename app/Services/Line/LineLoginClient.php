<?php

namespace App\Services\Line;

use App\Exceptions\LineApiException;
use Illuminate\Support\Facades\Http;

/**
 * LINE Login v2.1（OAuth 2.0 + OpenID Connect）授權碼流程。
 */
class LineLoginClient
{
    private const AUTHORIZE_URL = 'https://access.line.me/oauth2/v2.1/authorize';

    private const TOKEN_URL = 'https://api.line.me/oauth2/v2.1/token';

    private const VERIFY_URL = 'https://api.line.me/oauth2/v2.1/verify';

    public function isConfigured(): bool
    {
        return filled(config('services.line_login.channel_id')) && filled(config('services.line_login.channel_secret'));
    }

    public function authorizeUrl(string $state, string $nonce): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.line_login.channel_id'),
            'redirect_uri' => $this->callbackUrl(),
            'state' => $state,
            'nonce' => $nonce,
            'scope' => 'openid profile',
            // 登入時引導加入官方帳號好友，否則之後無法推播通知
            'bot_prompt' => 'aggressive',
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    /**
     * 以授權碼換取並驗證 ID token。
     *
     * @return array{sub: string, name: ?string}
     */
    public function authenticate(string $code, string $nonce): array
    {
        $token = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->callbackUrl(),
            'client_id' => config('services.line_login.channel_id'),
            'client_secret' => config('services.line_login.channel_secret'),
        ]);
        if ($token->failed() || ! $token->json('id_token')) {
            throw new LineApiException('LINE Login 取得 token 失敗：'.$token->body(), retryable: false);
        }

        // 由 LINE 驗證 ID token 的簽章、有效期與 nonce，避免自行實作 JWT 驗證
        $profile = Http::asForm()->timeout(10)->post(self::VERIFY_URL, [
            'id_token' => $token->json('id_token'),
            'client_id' => config('services.line_login.channel_id'),
            'nonce' => $nonce,
        ]);
        if ($profile->failed() || ! $profile->json('sub') || $profile->json('nonce') !== $nonce) {
            throw new LineApiException('LINE Login ID token 驗證失敗：'.$profile->body(), retryable: false);
        }

        return ['sub' => $profile->json('sub'), 'name' => $profile->json('name')];
    }

    private function callbackUrl(): string
    {
        return route('line.login.callback');
    }
}
