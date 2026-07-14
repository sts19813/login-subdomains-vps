<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\SsoBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $workspaces = $request->user()
            ->activeWorkspaces()
            ->orderBy('name')
            ->get();

        return view('workspaces.index', compact('workspaces'));
    }

    public function launch(Request $request, Workspace $workspace, SsoBroker $broker): RedirectResponse
    {
        return $broker->redirect($request->user(), $workspace, $request);
    }
}
