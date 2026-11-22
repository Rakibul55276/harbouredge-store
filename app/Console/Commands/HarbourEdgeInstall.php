<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Webkul\Installer\Database\Seeders\DatabaseSeeder as BagistoDatabaseSeeder;

/**
 * Installs the HarbourEdge store without ever wiping the database.
 *
 * Unlike `bagisto:install` (which runs db:wipe), this only migrates and seeds. It refuses to run
 * against a database that already holds another system's tables, such as the `manpower` ERP.
 */
class HarbourEdgeInstall extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'harbouredge:install
        {--admin-email= : Email for the first store admin (default admin@harboureg.com)}
        {--admin-password= : Password for the first store admin (random if omitted)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely install the HarbourEdge store (never wipes; refuses databases used by other systems)';

    /**
     * Whether the database holds tables that are not the store's own, so it must not be used unprefixed.
     */
    protected function hasForeignTables(string $prefix): bool
    {
        if (filled($prefix)) {
            return false;
        }

        $tables = collect(Schema::getTableListing(DB::connection()->getDatabaseName(), false));

        return $tables->isNotEmpty() && ! $tables->contains('channels');
    }

    /**
     * Whether B2B Suite's own base data has already been seeded.
     */
    protected function b2bSeeded(): bool
    {
        return Schema::hasTable('b2b_company_attributes')
            && DB::table('b2b_company_attributes')->exists();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $prefix = DB::connection()->getTablePrefix();

        $database = DB::connection()->getDatabaseName();

        if ($this->hasForeignTables($prefix)) {
            $this->error("Database `{$database}` already contains tables from another system (for example the ERP).");
            $this->error('Use a separate database for the store (e.g. harbouredge_store), or set DB_PREFIX so store tables cannot clash.');

            return self::FAILURE;
        }

        $this->components->info("Installing into database `{$database}`. Nothing is wiped or dropped.");

        if (blank(config('app.key'))) {
            $this->call('key:generate', ['--force' => true]);
        }

        $this->components->task('Creating store tables', fn () => $this->call('migrate', ['--force' => true]) === 0);

        if (! DB::table('channels')->exists()) {
            $this->components->task('Seeding base data', function () {
                app(BagistoDatabaseSeeder::class)->run([
                    'default_locale' => 'en',
                    'allowed_locales' => ['en', 'ar'],
                    'default_currency' => 'SAR',
                    'allowed_currencies' => ['SAR'],
                    'skip_admin_creation' => true,
                ]);
            });
        } else {
            $this->components->warn('Base data already present, skipping base seeding.');
        }

        $password = null;

        if (! DB::table('admins')->exists()) {
            $password = $this->option('admin-password') ?: Str::password(16, symbols: false);

            DB::table('admins')->insert([
                'name' => 'HarbourEdge Admin',
                'email' => $this->option('admin-email') ?: 'admin@harboureg.com',
                'password' => bcrypt($password),
                'role_id' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        touch(storage_path('installed'));

        $this->components->info('Base store ready. Installing B2B Suite and HarbourEdge data...');

        $steps = [];

        if (! $this->b2bSeeded()) {
            $steps[] = 'b2b-suite:install --no-interaction';
        }

        $steps[] = 'db:seed --class="Database\\Seeders\\HarbourEdgeSeeder" --force';
        $steps[] = 'indexer:index';

        if (! file_exists(public_path('storage'))) {
            $steps[] = 'storage:link';
        }

        foreach ($steps as $step) {
            $result = Process::path(base_path())->forever()->run(PHP_BINARY.' artisan '.$step, function (string $type, string $output) {
                $this->output->write($output);
            });

            if ($result->failed()) {
                $this->error("Step failed: php artisan {$step}");

                return self::FAILURE;
            }
        }

        $this->call('optimize:clear');

        $this->newLine();
        $this->components->info('HarbourEdge store installed.');

        if ($password) {
            $this->components->twoColumnDetail('Admin URL', rtrim(config('app.url'), '/').'/'.config('app.admin_url'));
            $this->components->twoColumnDetail('Admin email', $this->option('admin-email') ?: 'admin@harboureg.com');
            $this->components->twoColumnDetail('Admin password', $password);
            $this->components->warn('Save this password now, it is not shown again.');
        }

        return self::SUCCESS;
    }
}
