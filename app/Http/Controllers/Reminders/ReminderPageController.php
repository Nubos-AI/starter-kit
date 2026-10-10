<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reminders;

use App\Http\Controllers\Abstracts\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ReminderPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('reminders/MyReminders');
    }
}
