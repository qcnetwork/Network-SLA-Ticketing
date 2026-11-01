<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Services\DatabaseBackup;
use App\Services\DatabaseCopier;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

/**
 * DatabaseMigrations rather than RefreshDatabase: VACUUM INTO cannot run inside the transaction
 * RefreshDatabase wraps each test in.
 */
class DatabaseBackupTest extends TestCase
{
    use DatabaseMigrations;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'netticket-backup-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function ticketsIn(string $path): int
    {
        return (int) (new PDO('sqlite:'.$path))->query('select count(*) from tickets')->fetchColumn();
    }

    public function test_snapshot_is_a_complete_sqlite_database(): void
    {
        Ticket::factory()->count(3)->create();

        $path = app(DatabaseBackup::class)->create($this->directory);

        $this->assertStringStartsWith('SQLite format 3', file_get_contents($path, length: 16));
        $this->assertSame(3, $this->ticketsIn($path));
        $this->assertMatchesRegularExpression('/netticket-backup-\d{8}-\d{6}\.sqlite$/', $path);
    }

    public function test_snapshot_leaves_out_live_sessions_and_cache(): void
    {
        DB::table('sessions')->insert(['id' => 'live-session', 'payload' => 'secret', 'last_activity' => time()]);
        DB::table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => time() + 60]);

        $path = app(DatabaseBackup::class)->create($this->directory);
        $snapshot = new PDO('sqlite:'.$path);

        $this->assertSame(0, (int) $snapshot->query('select count(*) from sessions')->fetchColumn());
        $this->assertSame(0, (int) $snapshot->query('select count(*) from cache')->fetchColumn());
        $this->assertStringNotContainsString('live-session', file_get_contents($path));
    }

    public function test_prune_keeps_only_the_newest_backups(): void
    {
        $backup = app(DatabaseBackup::class);
        foreach (['2026-10-01 10:00', '2026-10-02 10:00', '2026-10-03 10:00'] as $time) {
            $this->travelTo($time);
            $backup->create($this->directory);
        }

        $this->assertSame(1, $backup->prune(2, $this->directory));
        $this->assertSame(
            ['netticket-backup-20261002-100000.sqlite', 'netticket-backup-20261003-100000.sqlite'],
            collect(File::files($this->directory))->map->getFilename()->sort()->values()->all(),
        );
    }

    public function test_supervisor_downloads_a_backup_after_confirming_their_password(): void
    {
        Ticket::factory()->count(2)->create();
        $supervisor = $this->userWithRole(Role::SPV, ['password' => 'rahasia123']);

        $this->actingAs($supervisor)->get('/settings')->assertSee('Backup Database')->assertSee(route('settings.backup'));
        $this->post('/settings/backup', ['password' => 'salah'])->assertSessionHasErrors('password');

        $response = $this->post('/settings/backup', ['password' => 'rahasia123']);
        $response->assertOk()->assertDownload();
        $this->assertSame('application/vnd.sqlite3', $response->headers->get('Content-Type'));

        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertSame(2, $this->ticketsIn($path));
        File::delete($path);
    }

    public function test_only_supervisors_can_back_up(): void
    {
        foreach ([Role::CS, Role::HELPDESK, Role::TEKNISI] as $role) {
            $user = $this->userWithRole($role, ['password' => 'rahasia123']);
            // A fresh session per user; AuthenticateSession would otherwise log out the switched-in account.
            $this->flushSession();
            $this->actingAs($user)->post('/settings/backup', ['password' => 'rahasia123'])->assertForbidden();
        }
    }

    public function test_backup_command_saves_into_storage(): void
    {
        $before = File::glob(storage_path(DatabaseBackup::DIRECTORY).'/netticket-backup-*.sqlite');

        $this->artisan('db:backup')->assertSuccessful();

        $created = array_values(array_diff(File::glob(storage_path(DatabaseBackup::DIRECTORY).'/netticket-backup-*.sqlite'), $before));
        $this->assertCount(1, $created);
        File::delete($created);
    }

    public function test_sqlite_databases_only_offer_the_sqlite_format(): void
    {
        $this->assertSame(['sqlite'], array_keys(DatabaseBackup::formats()));
        $this->expectExceptionMessage('Format backup sql tidak tersedia');
        app(DatabaseBackup::class)->create($this->directory, 'sql');
    }

    public function test_copier_moves_every_row_into_a_freshly_migrated_database(): void
    {
        $ticket = Ticket::factory()->assignedTo($this->userWithRole(Role::TEKNISI))->create();
        $path = $this->directory.DIRECTORY_SEPARATOR.'copy.sqlite';
        File::ensureDirectoryExists($this->directory);
        File::put($path, '');
        config(['database.connections.copy_target' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => false]]);
        $this->artisan('migrate', ['--database' => 'copy_target'])->assertSuccessful();

        $copier = app(DatabaseCopier::class);
        $counts = $copier->copy(DB::connection(), DB::connection('copy_target'), $copier->tables(DB::connection()));

        $this->assertSame(1, $counts['tickets']);
        $this->assertSame(1, $counts['ticket_technician']);
        // Categories seeded by the migrations are replaced, not doubled.
        $this->assertSame(DB::table('ticket_categories')->count(), $counts['ticket_categories']);
        $this->assertSame($ticket->ticket_number, DB::connection('copy_target')->table('tickets')->value('ticket_number'));
        DB::purge('copy_target');
    }
}
