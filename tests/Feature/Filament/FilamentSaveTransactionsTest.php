<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionProperty;
use RuntimeException;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Filament's own begin/commit/rollback around a save is a no-op unless transactions are switched on, and the panels
 * deliberately do NOT switch them on panel-wide: that would also wrap every Action, and several actions dispatch
 * queued jobs/notifications before the commit (`after_commit` is false) - see `.claude/rules/filament.md`
 * ("Transakcje zapisu"). Instead the audited pages opt in with `$hasDatabaseTransactions = true`.
 *
 * Each failure below is injected AFTER the record's own INSERT/UPDATE already ran, in the code that runs after it
 * (stock routing, role assignment). Without the transaction those writes stayed in the DB while the user saw an error.
 * RefreshDatabase wraps each test in a transaction, so the page's transaction is a savepoint here - exactly what a
 * rollback to the pre-save state needs, and the control test shows the same failure DOES leave a partial write
 * when the page's flag is off.
 */
class FilamentSaveTransactionsTest extends TestCase
{
    use RefreshDatabase, ServiceFormTestSupport;

    /** Pages that opt in. Adding one = audit its side effects first (rule "Transakcje zapisu"), then list it here. */
    private const TRANSACTIONAL_PAGES = [
        CreateCustomer::class,
        CreateEmployee::class,
        CreateService::class,
        EditService::class,
    ];

    private Location $primary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpServiceFormTenant();
        $this->primary = $this->addLocation();
    }

    // ---------------------------------------------------------------------------------------------
    // Guard: where transactions are on, and where they are not
    // ---------------------------------------------------------------------------------------------

    public function test_neither_panel_enables_transactions_panel_wide(): void
    {
        // Panel-wide also turns on `->databaseTransaction()` for EVERY Action (Panel::register), including the ones
        // that dispatch queued work before the commit. Lifting this needs the blockers fixed first.
        $this->assertFalse(Filament::getPanel('admin')->hasDatabaseTransactions());
        $this->assertFalse(Filament::getPanel('platform')->hasDatabaseTransactions());
    }

    public function test_exactly_the_audited_pages_opt_in_and_no_action_does(): void
    {
        $optedIn = [];
        $actionsOptedIn = [];

        foreach (Finder::create()->files()->in(app_path('Filament'))->name('*.php') as $file) {
            $source = $file->getContents();

            // A property declaration, an assignment in code, or an override of the getter - not a docblock mention.
            $setsFlag = '/^\s*(?:(?:public|protected|private)\s+\??bool\s+\$|\$this->)hasDatabaseTransactions\s*=|function\s+hasDatabaseTransactions\s*\(/m';

            if (preg_match($setsFlag, $source) === 1
                && preg_match('/^namespace\s+([^;]+);/m', $source, $ns) === 1) {
                $optedIn[] = $ns[1].'\\'.$file->getFilenameWithoutExtension();
            }

            if (preg_match('/->databaseTransaction\s*\(/', $source) === 1) {
                $actionsOptedIn[] = $file->getRelativePathname();
            }
        }

        sort($optedIn);
        $this->assertSame(self::TRANSACTIONAL_PAGES, $optedIn, 'a page changed its transaction setting without the audit / this list');
        $this->assertSame([], $actionsOptedIn, 'an Action opted into a transaction - audit it like a page and extend this test');

        foreach (self::TRANSACTIONAL_PAGES as $page) {
            $this->assertTrue((new ReflectionProperty($page, 'hasDatabaseTransactions'))->getDefaultValue(), $page);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Product edit page: full save and per-tab save
    // ---------------------------------------------------------------------------------------------

    public function test_a_failure_in_after_save_rolls_back_the_whole_full_save(): void
    {
        [$service, $before, $stockBefore] = $this->rentalWithStock();

        $page = $this->openEdit($service)
            ->set('data.name', 'Nowa nazwa')
            ->set('data.price_per_day', 150)
            ->set('data.quantity_total', 11)
            ->instance();

        $this->failOnStockWrite();
        $this->assertThrows(fn () => $page->save(), RuntimeException::class, 'simulated failure in stock routing');

        $this->assertSame($before, $this->snapshot($service), 'the services UPDATE survived a failure that came after it');
        $this->assertSame($stockBefore, $this->stockRows($service));
    }

    public function test_control_without_the_page_flag_the_same_failure_leaves_the_update_written(): void
    {
        [$service, , $stockBefore] = $this->rentalWithStock();

        $page = $this->openEdit($service)->set('data.name', 'Nowa nazwa')->set('data.quantity_total', 11)->instance();
        (fn () => $this->hasDatabaseTransactions = false)->call($page);

        $this->failOnStockWrite();
        $this->assertThrows(fn () => $page->save(), RuntimeException::class);

        $this->assertSame('Nowa nazwa', $this->reload($service)->name, 'the scenario no longer reaches a write before the failure - the rollback tests prove nothing');
        $this->assertSame($stockBefore, $this->stockRows($service));
    }

    public function test_a_failure_in_after_save_rolls_back_a_tab_save(): void
    {
        [$service, $before, $stockBefore] = $this->rentalWithStock();

        $page = $this->openEdit($service)
            ->set('data.price_per_day', 150)
            ->set('data.quantity_total', 11)
            ->instance();

        $this->failOnStockWrite();
        $this->assertThrows(fn () => $page->saveTab('cena-i-magazyn::data::tab'), RuntimeException::class, 'simulated failure in stock routing');

        $this->assertSame($before, $this->snapshot($service), 'the tab\'s UPDATE survived a failure that came after it');
        $this->assertSame($stockBefore, $this->stockRows($service));
        $this->assertSame('100.00', (string) $page->getRecord()->price_per_day, 'the in-memory record must match the rolled-back row');
        $this->assertFalse($page->getRecord()->isDirty());
    }

    public function test_a_failure_in_a_model_saved_hook_rolls_back_a_tab_save(): void
    {
        [$service, $before] = $this->rentalWithStock();

        $page = $this->openEdit($service)->set('data.meta_title', 'BOOM')->instance();
        $this->failOnSavedBoom();

        // The message matters: a refused tab key is an HttpException, also a RuntimeException, with nothing written.
        $this->assertThrows(fn () => $page->saveTab('seo-i-konwersja::data::tab'), RuntimeException::class, 'simulated failure after the UPDATE');

        $this->assertSame($before, $this->snapshot($service));
    }

    public function test_control_without_the_page_flag_a_failed_tab_save_leaves_the_update_written(): void
    {
        [$service] = $this->rentalWithStock();

        $page = $this->openEdit($service)->set('data.meta_title', 'BOOM')->instance();
        (fn () => $this->hasDatabaseTransactions = false)->call($page);
        $this->failOnSavedBoom();

        $this->assertThrows(fn () => $page->saveTab('seo-i-konwersja::data::tab'), RuntimeException::class, 'simulated failure after the UPDATE');

        $this->assertSame('BOOM', $this->reload($service)->meta_title, 'the tab scenario no longer reaches the UPDATE - the tab rollback tests prove nothing');
    }

    public function test_after_a_rolled_back_save_the_next_save_on_the_page_still_commits(): void
    {
        [$service] = $this->rentalWithStock();
        $component = $this->openEdit($service)->set('data.quantity_total', 11);
        $page = $component->instance();

        $armed = true;
        ServiceLocationStock::saving(function () use (&$armed): void {
            if ($armed) {
                throw new RuntimeException('simulated failure in stock routing');
            }
        });

        $this->assertThrows(fn () => $page->saveTab('cena-i-magazyn::data::tab'), RuntimeException::class, 'simulated failure in stock routing');
        $this->assertSame(4, $this->reload($service)->quantity_total);

        $armed = false;
        $page->saveTab('cena-i-magazyn::data::tab');

        $this->assertSame(11, $this->reload($service)->quantity_total);
        $this->assertSame([['location_id' => $this->primary->id, 'quantity' => 11, 'is_active' => true]], $this->stockRows($service));
    }

    // ---------------------------------------------------------------------------------------------
    // Product create page and the user create pages (employee, customer)
    // ---------------------------------------------------------------------------------------------

    public function test_a_failure_in_after_create_leaves_no_product_behind(): void
    {
        $this->failOnStockWrite();

        $component = Livewire::test(CreateService::class)->fillForm([
            'service_type' => 'item_rental',
            'name' => 'Wiertarka bez stanu',
            'slug' => 'wiertarka-bez-stanu',
            'price_per_day' => 10,
            'quantity_total' => 3,
        ]);

        $this->assertThrows(fn () => $component->instance()->create(), RuntimeException::class, 'simulated failure in stock routing');

        $this->assertFalse(Service::withoutGlobalScopes()->where('slug', 'wiertarka-bez-stanu')->exists(), 'the INSERT survived a failure in afterCreate');
        $this->assertSame(0, ServiceLocationStock::withoutGlobalScope('organization')->count(), 'stock rows written before the failure survived');
    }

    public function test_a_failure_in_after_create_leaves_no_half_created_employee(): void
    {
        // A tenant without the `staff` role: assignRole() throws after the user row was INSERTed. Before the
        // transaction this left an account without role and pivot - it exists, but cannot log in.
        Role::where('name', 'staff')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $usersBefore = User::count();

        $component = Livewire::test(CreateEmployee::class)->fillForm([
            'first_name' => 'Anna',
            'last_name' => 'Nowak',
            'email' => 'anna@example.test',
            'password' => 'sekretne-haslo-123',
        ]);

        $this->assertThrows(fn () => $component->instance()->create(), RoleDoesNotExist::class);

        $this->assertFalse(User::where('email', 'anna@example.test')->exists(), 'the user INSERT survived a failure in afterCreate');
        $this->assertSame($usersBefore, User::count());
    }

    public function test_a_failure_in_after_create_leaves_no_half_created_customer(): void
    {
        // Same shape: a tenant without the `customer` role, so assignRole() throws after the user row was INSERTed.
        Role::where('name', 'customer')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $usersBefore = User::count();

        $component = Livewire::test(CreateCustomer::class)->fillForm([
            'first_name' => 'Jan',
            'last_name' => 'Klient',
            'email' => 'jan@example.test',
            'password' => 'sekretne-haslo-123',
        ]);

        $this->assertThrows(fn () => $component->instance()->create(), RoleDoesNotExist::class);

        $this->assertFalse(User::where('email', 'jan@example.test')->exists(), 'the user INSERT survived a failure in afterCreate');
        $this->assertSame($usersBefore, User::count());
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    /**
     * A single-location rental whose "Ilość w magazynie" routes into the primary stock row in `afterSave()`.
     *
     * @return array{0: Service, 1: array<string, mixed>, 2: array<int, array{location_id: int, quantity: int, is_active: bool}>}
     */
    private function rentalWithStock(): array
    {
        $service = $this->rentalService(['quantity_total' => 4, 'meta_title' => 'Meta początkowy']);
        $this->setStock($service, $this->primary, 4);

        return [$service, $this->snapshot($service), $this->stockRows($service)];
    }

    private function failOnSavedBoom(): void
    {
        Service::saved(function (Service $row): void {
            if ($row->meta_title === 'BOOM') {
                throw new RuntimeException('simulated failure after the UPDATE');
            }
        });
    }

    private function failOnStockWrite(): void
    {
        ServiceLocationStock::saving(function (): void {
            throw new RuntimeException('simulated failure in stock routing');
        });
    }
}
