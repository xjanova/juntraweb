<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EnvWriter;
use App\Support\Installation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PDO;

class InstallerController extends Controller
{
    public function welcome(): View
    {
        $checks = $this->requirementChecks();
        return view('install.welcome', [
            'checks' => $checks,
            'allOk'  => collect($checks)->every(fn($c) => $c['ok']),
        ]);
    }

    public function databaseForm(Request $request): View
    {
        return view('install.database', [
            'data' => $request->session()->get('install.db', [
                'connection' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'juntraweb',
                'username' => 'root',
                'password' => '',
            ]),
        ]);
    }

    public function databaseSave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'connection' => 'required|in:mysql,mariadb,sqlite',
            'host' => 'nullable|string|max:191',
            'port' => 'nullable|string|max:6',
            'database' => 'required|string|max:128',
            'username' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:128',
        ]);

        $request->session()->put('install.db', $data);

        try {
            $this->testConnection($data);
        } catch (\Throwable $e) {
            return back()->withErrors(['connection' => 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . $e->getMessage()])->withInput();
        }

        // Persist to .env so subsequent boots use it.
        $env = $this->envFromDbForm($data);
        EnvWriter::set($env);

        // Re-bind config + DB connection in this same request, then run migrations.
        $this->applyDbConfig($data);

        try {
            Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            Artisan::call('db:seed', ['--class' => 'ZodiacSeeder', '--force' => true]);
            Artisan::call('db:seed', ['--class' => 'TarotCardSeeder', '--force' => true]);
            Artisan::call('db:seed', ['--class' => 'SettingSeeder', '--force' => true]);
            Artisan::call('db:seed', ['--class' => 'TestimonialSeeder', '--force' => true]);
        } catch (\Throwable $e) {
            return back()->withErrors(['connection' => 'รัน migration ไม่สำเร็จ: ' . $e->getMessage()])->withInput();
        }

        return redirect()->route('install.admin')->with('status', 'สร้างฐานข้อมูลและตารางเรียบร้อย');
    }

    public function adminForm(Request $request): View
    {
        return view('install.admin', [
            'data' => $request->session()->get('install.admin', [
                'name' => 'แม่หมอจันทรา',
                'email' => '',
            ]),
        ]);
    }

    public function adminSave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:64',
            'email' => 'required|email|max:128',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $this->ensureDbConfigured($request);

        try {
            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make($data['password']),
                    'role' => 'admin',
                    'email_verified_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['email' => 'สร้างผู้ดูแลไม่สำเร็จ: ' . $e->getMessage()])->withInput();
        }

        $request->session()->put('install.admin', ['name' => $data['name'], 'email' => $data['email']]);

        return redirect()->route('install.integrations')->with('status', 'สร้างผู้ดูแลระบบเรียบร้อย');
    }

    public function integrationsForm(Request $request): View
    {
        $this->ensureDbConfigured($request);
        return view('install.integrations', [
            'data' => $request->session()->get('install.integrations', [
                'thaiprompt_enabled' => false,
                'thaiprompt_base_url' => 'https://thaiprompt.com',
                'thaiprompt_client_id' => '',
                'thaiprompt_client_secret' => '',
                'ai_provider' => 'gemini',
                'ai_model' => 'gemini-2.0-flash-exp',
                'ai_api_key' => '',
            ]),
        ]);
    }

    public function integrationsSave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'thaiprompt_enabled' => 'nullable|in:0,1,on',
            'thaiprompt_base_url' => 'nullable|url|max:255',
            'thaiprompt_client_id' => 'nullable|string|max:128',
            'thaiprompt_client_secret' => 'nullable|string|max:255',
            'ai_provider' => 'nullable|in:gemini,openai,anthropic',
            'ai_model' => 'nullable|string|max:64',
            'ai_api_key' => 'nullable|string|max:255',
            'site_name' => 'nullable|string|max:64',
            'site_tagline' => 'nullable|string|max:255',
        ]);

        $this->ensureDbConfigured($request);

        $tpEnabled = (bool) ($data['thaiprompt_enabled'] ?? false);

        \App\Models\Setting::put('thaiprompt_enabled', $tpEnabled ? '1' : '0', 'thaiprompt');
        \App\Models\Setting::put('thaiprompt_base_url', $data['thaiprompt_base_url'] ?? '', 'thaiprompt');
        \App\Models\Setting::put('thaiprompt_client_id', $data['thaiprompt_client_id'] ?? '', 'thaiprompt');
        if (!empty($data['thaiprompt_client_secret'])) {
            \App\Models\Setting::put('thaiprompt_client_secret', $data['thaiprompt_client_secret'], 'thaiprompt', true);
        }

        \App\Models\Setting::put('ai_provider', $data['ai_provider'] ?? 'gemini', 'ai');
        \App\Models\Setting::put('ai_model', $data['ai_model'] ?? 'gemini-2.0-flash-exp', 'ai');
        if (!empty($data['ai_api_key'])) {
            \App\Models\Setting::put('ai_api_key', $data['ai_api_key'], 'ai', true);
        }

        if (!empty($data['site_name'])) \App\Models\Setting::put('site_name', $data['site_name']);
        if (!empty($data['site_tagline'])) \App\Models\Setting::put('site_tagline', $data['site_tagline']);

        return redirect()->route('install.finish');
    }

    public function finish(Request $request): View
    {
        Installation::markInstalled();
        $request->session()->forget(['install.db', 'install.admin', 'install.integrations']);

        // Clear caches so settings + new env take effect for normal users.
        try {
            Artisan::call('config:clear');
            Artisan::call('cache:clear');
            Artisan::call('view:clear');
        } catch (\Throwable $e) {
            // best-effort
        }

        return view('install.finish');
    }

    /* ---------- helpers ---------- */

    private function requirementChecks(): array
    {
        $writableEnv = is_writable(base_path('.env')) || is_writable(base_path());
        $writableStorage = is_writable(storage_path()) || (!is_dir(storage_path()) && @mkdir(storage_path(), 0775, true));

        return [
            ['label' => 'PHP >= 8.2', 'ok' => version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['label' => 'PDO MySQL extension', 'ok' => extension_loaded('pdo_mysql')],
            ['label' => 'PDO SQLite extension', 'ok' => extension_loaded('pdo_sqlite')],
            ['label' => 'OpenSSL extension', 'ok' => extension_loaded('openssl')],
            ['label' => 'Mbstring extension', 'ok' => extension_loaded('mbstring')],
            ['label' => 'JSON extension', 'ok' => extension_loaded('json')],
            ['label' => 'cURL extension', 'ok' => extension_loaded('curl')],
            ['label' => 'GD extension', 'ok' => extension_loaded('gd')],
            ['label' => '.env writable', 'ok' => $writableEnv],
            ['label' => 'storage/ writable', 'ok' => $writableStorage],
        ];
    }

    private function envFromDbForm(array $data): array
    {
        if ($data['connection'] === 'sqlite') {
            $sqliteAbs = database_path('database.sqlite');
            if (!is_file($sqliteAbs)) @touch($sqliteAbs);
            return [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE'   => $sqliteAbs,
            ];
        }

        return [
            'DB_CONNECTION' => $data['connection'],
            'DB_HOST'       => $data['host'] ?? '127.0.0.1',
            'DB_PORT'       => $data['port'] ?? '3306',
            'DB_DATABASE'   => $data['database'],
            'DB_USERNAME'   => $data['username'] ?? '',
            'DB_PASSWORD'   => $data['password'] ?? '',
        ];
    }

    private function applyDbConfig(array $data): void
    {
        $name = $data['connection'];

        if ($name === 'sqlite') {
            $sqliteAbs = database_path('database.sqlite');
            if (!is_file($sqliteAbs)) @touch($sqliteAbs);
            Config::set('database.connections.sqlite.database', $sqliteAbs);
            Config::set('database.default', 'sqlite');
        } else {
            $key = "database.connections.$name";
            Config::set("$key.driver", $name);
            Config::set("$key.host", $data['host'] ?? '127.0.0.1');
            Config::set("$key.port", $data['port'] ?? '3306');
            Config::set("$key.database", $data['database']);
            Config::set("$key.username", $data['username'] ?? '');
            Config::set("$key.password", $data['password'] ?? '');
            Config::set('database.default', $name);
        }

        DB::purge('mysql');
        DB::purge('mariadb');
        DB::purge('sqlite');
        DB::reconnect();
    }

    private function testConnection(array $data): void
    {
        if ($data['connection'] === 'sqlite') {
            $abs = database_path('database.sqlite');
            if (!is_file($abs)) @touch($abs);
            new PDO('sqlite:' . $abs);
            return;
        }

        $host = $data['host'] ?: '127.0.0.1';
        $port = $data['port'] ?: '3306';
        $db   = $data['database'];
        $user = $data['username'] ?: 'root';
        $pass = $data['password'] ?: '';

        // First try to connect; if the database does not exist, create it.
        try {
            new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Unknown database')) {
                $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
                $pdo->exec("CREATE DATABASE `" . str_replace('`', '', $db) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } else {
                throw $e;
            }
        }
    }

    private function ensureDbConfigured(Request $request): void
    {
        $data = $request->session()->get('install.db');
        if ($data) {
            $this->applyDbConfig($data);
            return;
        }
        // Otherwise rely on .env (already written by the DB step).
    }
}
