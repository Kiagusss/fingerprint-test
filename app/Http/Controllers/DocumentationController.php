<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Diagnostics;
use Illuminate\View\View;

/**
 * "Dokumentasi Konfigurasi" page. Renders the environment snapshot plus the
 * runbook (install, HTTPS, two-account test procedure, rollback).
 */
class DocumentationController extends Controller
{
    public function __construct(private readonly Diagnostics $diagnostics) {}

    public function index(): View
    {
        return view('documentation', [
            'server' => $this->diagnostics->snapshot(),
        ]);
    }
}
