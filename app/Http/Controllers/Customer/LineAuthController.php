<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\LineApiException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Line\LineLoginClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 客戶以 LINE Login 登入；首次使用需透過後台產生的一次性綁定連結。
 */
class LineAuthController extends Controller
{
    /** session 中記錄登入時的 LINE userId，供 EnsureCustomerLineBinding 比對 */
    public const SESSION_LINE_SUB = 'customer_line_sub';

    private const SESSION_BIND_TOKEN = 'line_bind_token';

    public function __construct(private readonly LineLoginClient $line) {}

    public function bind(Request $request, string $token): RedirectResponse
    {
        $customer = $this->findByBindToken($token);
        if (! $customer) {
            return $this->fail('bind_invalid');
        }

        $request->session()->put(self::SESSION_BIND_TOKEN, $token);

        return redirect()->route('line.login');
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->line->isConfigured()) {
            return $this->fail('line_not_configured');
        }

        $state = Str::random(40);
        $nonce = Str::random(40);
        $request->session()->put('line_login', compact('state', 'nonce'));

        return redirect()->away($this->line->authorizeUrl($state, $nonce));
    }

    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('line_login');

        if ($request->filled('error')) {
            return $this->fail('cancelled');
        }
        if (! $pending || ! hash_equals($pending['state'], (string) $request->query('state'))) {
            return $this->fail('state');
        }

        try {
            $profile = $this->line->authenticate((string) $request->query('code'), $pending['nonce']);
        } catch (LineApiException $e) {
            report($e);

            return $this->fail('line_error');
        }

        $bindToken = $request->session()->pull(self::SESSION_BIND_TOKEN);
        $customer = $bindToken
            ? $this->bindCustomer($bindToken, $profile)
            : Customer::where('line_user_id', $profile['sub'])->first();

        if (is_string($customer)) {
            return $this->fail($customer);
        }
        if (! $customer || ! $customer->is_active) {
            return $this->fail('not_bound');
        }

        Auth::guard('customer')->login($customer, remember: true);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_LINE_SUB, $profile['sub']);

        return redirect('/');
    }

    public function logout(Request $request): Response
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * @param  array{sub: string, name: ?string}  $profile
     * @return Customer|string 綁定後的客戶，或錯誤代碼
     */
    private function bindCustomer(string $token, array $profile): Customer|string
    {
        return DB::transaction(function () use ($token, $profile) {
            $customer = $this->findByBindToken($token);
            if (! $customer) {
                return 'bind_invalid';
            }
            if (Customer::where('line_user_id', $profile['sub'])->whereKeyNot($customer->id)->exists()) {
                return 'line_in_use';
            }

            $customer->forceFill([
                'line_user_id' => $profile['sub'],
                'line_display_name' => $profile['name'],
                'line_bound_at' => now(),
                'line_bind_token' => null,
                'line_bind_token_expires_at' => null,
            ])->save();

            return $customer;
        });
    }

    private function findByBindToken(string $token): ?Customer
    {
        return Customer::where('line_bind_token', $token)
            ->where('line_bind_token_expires_at', '>', now())
            ->first();
    }

    private function fail(string $code): RedirectResponse
    {
        return redirect("/login?error={$code}");
    }
}
