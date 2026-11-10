<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenancy;

use App\Http\Controllers\Abstracts\Controller;
use App\Support\Tenancy\WelcomeTenantResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TlsCertificateApprovalController extends Controller
{
    public function __construct(private readonly WelcomeTenantResolver $welcome) {}

    public function __invoke(Request $request): Response
    {
        $token = (string) config('tenancy.edge.tls_ask_token');

        if ($token === '' || !hash_equals($token, $this->queryOf($request, 'token'))) {
            Log::warning('TLS certificate request without a valid edge token refused.');

            return response('', 404);
        }

        $host = $this->queryOf($request, 'domain');
        $isApproved = $this->welcome->isCertifiable($host);

        Log::info('TLS certificate request decided.', ['host' => $host, 'is_approved' => $isApproved]);

        return response('', $isApproved ? 200 : 404);
    }

    private function queryOf(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }
}
