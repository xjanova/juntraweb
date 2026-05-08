<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Filament shortcut page that just opens the public /mlm dashboard with the
 * admin's session — useful so operators don't have to remember the URL.
 *
 * The actual rendering lives in resources/views/pages/mlm/dashboard.blade.php
 * (used by both the public route and this admin shortcut).
 */
class MlmAdminViewer extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-share';
    protected static ?string $navigationLabel = 'ผังทีม MLM';
    protected static ?string $title = 'ผังทีม MLM (จากดูดวง)';
    protected static ?string $navigationGroup = 'ดูดวง';
    protected static ?int $navigationSort = 80;
    protected static string $view = 'filament.pages.mlm-admin-viewer';

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }
}
