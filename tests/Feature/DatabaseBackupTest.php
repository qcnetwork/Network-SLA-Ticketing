<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Services\DatabaseBackup;
use Illuminate\Foundation\Testing\DatabaseMigrations;
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
}
