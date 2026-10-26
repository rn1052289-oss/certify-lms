<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Announcement\StoreRequest;
use App\Models\Announcement;
use App\UseCases\Announcement\CreateAction;
use App\UseCases\Announcement\IndexAction;
use App\UseCases\Announcement\StoreAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(IndexAction $action): View
    {
        return view('announcement.management.index', [
            'announcements' => $action(),
        ]);
    }

    public function create(CreateAction $action): View
    {
        return view('announcement.management.create', $action());
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $result = $action($request->user(), $request->validated());

        return redirect()->route('admin.announcements.show', $result['announcement'])
            ->with('success', '配信を受け付けました');
    }

    public function show(Announcement $announcement): View
    {
        $announcement->load(['targetCertification', 'targetUser', 'createdBy']);

        return view('announcement.management.show', ['announcement' => $announcement]);
    }
}
