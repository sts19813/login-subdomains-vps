<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\BillingAccessService;
use App\Services\StripeBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class BillingController extends Controller
{
    public function show(Request $request, Workspace $workspace, BillingAccessService $access): View
    {
        $this->ensureMembership($request, $workspace);

        return view('billing.show', [
            'workspace' => $workspace,
            'canManage' => $access->canManage($request->user(), $workspace),
            'canAccess' => $access->canAccess($request->user(), $workspace),
        ]);
    }

    public function checkout(
        Request $request,
        Workspace $workspace,
        BillingAccessService $access,
        StripeBillingService $stripe,
    ): RedirectResponse {
        $this->ensureMembership($request, $workspace);
        abort_unless($access->canManage($request->user(), $workspace), 403);

        if ($workspace->stripe_subscription_id && ! in_array($workspace->subscription_status, ['canceled', 'incomplete_expired'], true)) {
            return back()->withErrors(['billing' => 'La instancia ya tiene una suscripción. Usa “Administrar pago” para regularizarla.']);
        }

        try {
            return redirect()->away($stripe->createCheckout($workspace, $request->user())->url);
        } catch (RuntimeException $exception) {
            report($exception);

            return back()->withErrors(['billing' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['billing' => 'Stripe no pudo iniciar el pago. Intenta nuevamente.']);
        }
    }

    public function portal(
        Request $request,
        Workspace $workspace,
        BillingAccessService $access,
        StripeBillingService $stripe,
    ): RedirectResponse {
        $this->ensureMembership($request, $workspace);
        abort_unless($access->canManage($request->user(), $workspace), 403);

        try {
            return redirect()->away($stripe->createPortal($workspace)->url);
        } catch (RuntimeException $exception) {
            report($exception);

            return back()->withErrors(['billing' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['billing' => 'Stripe no pudo abrir el portal de pago. Intenta nuevamente.']);
        }
    }

    private function ensureMembership(Request $request, Workspace $workspace): void
    {
        abort_unless(
            $request->user()->activeWorkspaces()->whereKey($workspace->getKey())->exists(),
            403,
        );
    }
}
