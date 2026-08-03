<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CrmCustomer;
use App\Models\CrmQuote;
use App\Models\CrmQuoteItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CrmQuotePackingPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_updating_a_quote_preserves_existing_item_ids(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Packing Test Customer',
            'status' => 'active',
        ]);
        $quote = CrmQuote::query()->create([
            'customer_id' => $customer->id,
            'quote_no' => 'PACK-ID-001',
            'document_type' => 'packing_list',
            'title' => 'Stable item IDs',
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        $item = $quote->items()->create([
            'item_name' => 'Original machine',
            'quantity' => 1,
            'unit' => 'set',
            'unit_price' => 1000,
            'amount' => 1000,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.crm.quotes.update', ['quoteId' => $quote->id]), [
                'customer_id' => $customer->id,
                'quote_no' => $quote->quote_no,
                'document_type' => 'packing_list',
                'title' => $quote->title,
                'currency' => 'USD',
                'status' => 'draft',
                'items' => [
                    'id' => [$item->id],
                    'item_name' => ['Updated machine'],
                    'quantity' => [1],
                    'unit' => ['set'],
                    'unit_price' => [1000],
                ],
            ])
            ->assertRedirect(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]));

        $this->assertDatabaseHas('crm_quote_items', [
            'id' => $item->id,
            'quote_id' => $quote->id,
            'item_name' => 'Updated machine',
        ]);
        $this->assertSame(1, $quote->items()->count());
    }

    public function test_packing_plan_schema_supports_packages_allocations_and_lifecycle(): void
    {
        $this->assertTrue(Schema::hasColumns('crm_quotes', [
            'packing_mode',
            'packing_status',
            'packing_applied_at',
            'packing_applied_by_admin_id',
            'packing_invalid_reason',
        ]));
        $this->assertTrue(Schema::hasColumn('crm_quote_items', 'packing_exempt'));
        $this->assertTrue(Schema::hasTable('crm_quote_packages'));
        $this->assertTrue(Schema::hasTable('crm_quote_package_items'));
    }

    public function test_quote_update_rejects_item_ids_from_another_quote(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Owner Test', 'status' => 'active']);
        $firstQuote = $this->quote($customer, 'PACK-OWNER-001');
        $secondQuote = $this->quote($customer, 'PACK-OWNER-002');
        $firstItem = $firstQuote->items()->create($this->itemPayload('First quote item'));
        $foreignItem = $secondQuote->items()->create($this->itemPayload('Foreign quote item'));

        $this->actingAs($admin, 'admin')
            ->from(route('admin.crm.quotes.edit', ['quoteId' => $firstQuote->id]))
            ->put(route('admin.crm.quotes.update', ['quoteId' => $firstQuote->id]), [
                'customer_id' => $customer->id,
                'quote_no' => $firstQuote->quote_no,
                'document_type' => 'packing_list',
                'title' => $firstQuote->title,
                'items' => [
                    'id' => [$foreignItem->id],
                    'item_name' => ['Injected item'],
                    'quantity' => [1],
                    'unit' => ['set'],
                    'unit_price' => [100],
                ],
            ])
            ->assertRedirect(route('admin.crm.quotes.edit', ['quoteId' => $firstQuote->id]))
            ->assertSessionHasErrors('items.id');

        $this->assertDatabaseHas('crm_quote_items', ['id' => $firstItem->id, 'item_name' => 'First quote item']);
        $this->assertDatabaseHas('crm_quote_items', ['id' => $foreignItem->id, 'item_name' => 'Foreign quote item']);
    }

    public function test_partial_quote_update_preserves_items_and_existing_images(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Legacy Request Test', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-LEGACY-001');
        $item = $quote->items()->create(array_replace($this->itemPayload('Machine with image'), [
            'image_path' => '/storage/uploads/original-machine.png',
            'image_original_name' => 'original-machine.png',
        ]));

        $this->actingAs($admin, 'admin')
            ->put(route('admin.crm.quotes.update', ['quoteId' => $quote->id]), [
                'customer_id' => $customer->id,
                'quote_no' => $quote->quote_no,
                'document_type' => 'packing_list',
                'title' => 'Updated without item payload',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_quote_items', [
            'id' => $item->id,
            'image_path' => '/storage/uploads/original-machine.png',
            'image_original_name' => 'original-machine.png',
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.crm.quotes.update', ['quoteId' => $quote->id]), [
                'customer_id' => $customer->id,
                'quote_no' => $quote->quote_no,
                'document_type' => 'packing_list',
                'title' => 'Updated item without image fields',
                'items' => [
                    'id' => [$item->id],
                    'item_name' => ['Machine with image updated'],
                    'quantity' => [1],
                    'unit' => ['set'],
                    'unit_price' => [100],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_quote_items', [
            'id' => $item->id,
            'item_name' => 'Machine with image updated',
            'image_path' => '/storage/uploads/original-machine.png',
            'image_original_name' => 'original-machine.png',
        ]);
    }

    public function test_database_rejects_allocating_an_item_from_another_quote(): void
    {
        $customer = CrmCustomer::query()->create(['company_name' => 'Allocation Boundary', 'status' => 'active']);
        $firstQuote = $this->quote($customer, 'PACK-BOUNDARY-001');
        $secondQuote = $this->quote($customer, 'PACK-BOUNDARY-002');
        $foreignItem = $secondQuote->items()->create($this->itemPayload('Foreign item'));
        $package = DB::table('crm_quote_packages')->insertGetId([
            'quote_id' => $firstQuote->id,
            'package_no' => 'CASE-01',
            'package_type' => 'wooden_case',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(Schema::hasColumn('crm_quote_package_items', 'quote_id'));

        try {
            DB::table('crm_quote_package_items')->insert([
                'quote_id' => $firstQuote->id,
                'package_id' => $package,
                'quote_item_id' => $foreignItem->id,
                'allocated_quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Cross-quote package allocation was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('crm_quote_package_items', [
                'package_id' => $package,
                'quote_item_id' => $foreignItem->id,
            ]);
        }
    }

    public function test_quote_update_rejects_duplicate_item_ids(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Duplicate Item Test', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-DUPLICATE-001');
        $item = $quote->items()->create($this->itemPayload('Single item'));

        $this->actingAs($admin, 'admin')
            ->from(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->put(route('admin.crm.quotes.update', ['quoteId' => $quote->id]), [
                'customer_id' => $customer->id,
                'quote_no' => $quote->quote_no,
                'document_type' => 'packing_list',
                'title' => $quote->title,
                'items' => [
                    'id' => [$item->id, $item->id],
                    'item_name' => ['First duplicate', 'Second duplicate'],
                    'quantity' => [1, 1],
                    'unit' => ['set', 'set'],
                    'unit_price' => [100, 100],
                ],
            ])
            ->assertRedirect(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->assertSessionHasErrors('items.id');

        $this->assertDatabaseHas('crm_quote_items', ['id' => $item->id, 'item_name' => 'Single item']);
        $this->assertSame(1, $quote->items()->count());
    }

    public function test_valid_package_plan_can_be_applied_with_shared_and_split_items(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Packing Apply Test', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-APPLY-001');
        $machine = $quote->items()->create($this->itemPayload('Machine'));
        $consumables = $quote->items()->create(array_replace($this->itemPayload('Consumables'), [
            'quantity' => 100,
            'unit' => 'pcs',
            'amount' => 10000,
            'sort_order' => 2,
        ]));

        $this->actingAs($admin, 'admin')
            ->put(route('admin.crm.quotes.update', ['quoteId' => $quote->id]), array_replace(
                $this->baseUpdatePayload($quote, $customer),
                [
                    'packing_mode' => 'package_plan',
                    'packing_action' => 'apply',
                    'items' => $this->itemsRequest([$machine, $consumables]),
                    'packages' => [
                        $this->packagePayload('CASE-01', [
                            ['quote_item_id' => $machine->id, 'allocated_quantity' => 1],
                            ['quote_item_id' => $consumables->id, 'allocated_quantity' => 40],
                        ]),
                        $this->packagePayload('CASE-02', [
                            ['quote_item_id' => $consumables->id, 'allocated_quantity' => 60],
                        ]),
                    ],
                ],
            ))
            ->assertRedirect(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->assertSessionHasNoErrors();

        $quote->refresh();
        $this->assertSame('package_plan', $quote->packing_mode);
        $this->assertSame('applied', $quote->packing_status);
        $this->assertNotNull($quote->packing_applied_at);
        $this->assertSame($admin->id, $quote->packing_applied_by_admin_id);
        $this->assertSame(2, $quote->packages()->count());
        $this->assertEqualsWithDelta(100.0, (float) DB::table('crm_quote_package_items')
            ->where('quote_item_id', $consumables->id)
            ->sum('allocated_quantity'), 0.001);
    }

    public function test_overallocated_package_plan_is_rejected_and_rolled_back(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Packing Over Test', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-OVER-001');
        $item = $quote->items()->create(array_replace($this->itemPayload('Consumables'), [
            'quantity' => 100,
            'unit' => 'pcs',
        ]));

        $this->actingAs($admin, 'admin')
            ->from(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->put(route('admin.crm.quotes.update', ['quoteId' => $quote->id]), array_replace(
                $this->baseUpdatePayload($quote, $customer),
                [
                    'packing_mode' => 'package_plan',
                    'packing_action' => 'apply',
                    'items' => $this->itemsRequest([$item]),
                    'packages' => [
                        $this->packagePayload('CASE-01', [
                            ['quote_item_id' => $item->id, 'allocated_quantity' => 101],
                        ]),
                    ],
                ],
            ))
            ->assertRedirect(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->assertSessionHasErrors('packages');

        $quote->refresh();
        $this->assertSame('item_level', $quote->packing_mode);
        $this->assertSame('draft', $quote->packing_status);
        $this->assertSame(0, $quote->packages()->count());
    }

    public function test_item_quantity_change_invalidates_an_applied_package_plan(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Packing Invalid Test', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-INVALID-001');
        $item = $quote->items()->create($this->itemPayload('Machine'));
        $packageId = DB::table('crm_quote_packages')->insertGetId([
            'quote_id' => $quote->id,
            'package_no' => 'CASE-01',
            'package_type' => 'wooden_case',
            'net_weight' => 90,
            'gross_weight' => 100,
            'volume_cbm' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('crm_quote_package_items')->insert([
            'quote_id' => $quote->id,
            'package_id' => $packageId,
            'quote_item_id' => $item->id,
            'allocated_quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $quote->update([
            'packing_mode' => 'package_plan',
            'packing_status' => 'applied',
            'packing_applied_at' => now(),
            'packing_applied_by_admin_id' => $admin->id,
        ]);

        $items = $this->itemsRequest([$item]);
        $items['quantity'] = [2];
        $this->actingAs($admin, 'admin')
            ->put(route('admin.crm.quotes.update', ['quoteId' => $quote->id]), array_replace(
                $this->baseUpdatePayload($quote, $customer),
                ['items' => $items],
            ))
            ->assertRedirect();

        $quote->refresh();
        $this->assertSame('invalid', $quote->packing_status);
        $this->assertSame('', (string) $quote->packing_applied_at);
        $this->assertStringContainsString('商品数量', $quote->packing_invalid_reason);
    }

    public function test_edit_page_exposes_package_plan_workbench_with_existing_allocations(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Packing UI Test', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-UI-001');
        $item = $quote->items()->create($this->itemPayload('Machine for wooden case'));
        $packageId = DB::table('crm_quote_packages')->insertGetId([
            'quote_id' => $quote->id,
            'package_no' => 'CASE-01',
            'package_type' => 'wooden_case',
            'package_length' => 120,
            'package_width' => 80,
            'package_height' => 100,
            'net_weight' => 90,
            'gross_weight' => 100,
            'volume_cbm' => 0.96,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('crm_quote_package_items')->insert([
            'quote_id' => $quote->id,
            'package_id' => $packageId,
            'quote_item_id' => $item->id,
            'allocated_quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $quote->update(['packing_mode' => 'package_plan', 'packing_status' => 'draft']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->assertOk()
            ->assertSee('包装方案')
            ->assertSee('CASE-01')
            ->assertSee('Machine for wooden case')
            ->assertSee('应用包装方案');
    }

    public function test_packing_list_print_uses_only_an_applied_package_plan(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Packing Print Test', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-PRINT-001');
        $machine = $quote->items()->create($this->itemPayload('Machine inside shared case'));
        $accessory = $quote->items()->create(array_replace($this->itemPayload('Accessory inside shared case'), [
            'quantity' => 2,
            'unit' => 'pcs',
            'sort_order' => 2,
        ]));
        $packageId = DB::table('crm_quote_packages')->insertGetId([
            'quote_id' => $quote->id,
            'package_no' => 'CASE-SHARED-01',
            'package_type' => 'wooden_case',
            'package_length' => 120,
            'package_width' => 80,
            'package_height' => 100,
            'net_weight' => 90,
            'gross_weight' => 100,
            'volume_cbm' => 0.96,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([[$machine->id, 1], [$accessory->id, 2]] as [$itemId, $quantity]) {
            DB::table('crm_quote_package_items')->insert([
                'quote_id' => $quote->id,
                'package_id' => $packageId,
                'quote_item_id' => $itemId,
                'allocated_quantity' => $quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $quote->update(['packing_mode' => 'package_plan', 'packing_status' => 'applied']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.print', ['quoteId' => $quote->id, 'type' => 'packing_list', 'language' => 'en']))
            ->assertOk()
            ->assertSee('CASE-SHARED-01')
            ->assertSee('Machine inside shared case')
            ->assertSee('Accessory inside shared case')
            ->assertSee('data-package-plan-table', false);

        $quote->update(['packing_status' => 'draft']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.print', ['quoteId' => $quote->id, 'type' => 'packing_list', 'language' => 'en']))
            ->assertOk()
            ->assertDontSee('data-package-plan-table', false)
            ->assertSee('data-packing-print-blocked', false)
            ->assertDontSee('Machine inside shared case')
            ->assertDontSee('Accessory inside shared case')
            ->assertSee('包装方案尚未应用或已经失效');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.pdf', ['quoteId' => $quote->id, 'type' => 'packing_list', 'language' => 'en']))
            ->assertRedirect(route('admin.crm.quotes.print', ['quoteId' => $quote->id, 'type' => 'packing_list', 'language' => 'en']))
            ->assertSessionHas('error');
    }

    public function test_package_plan_pagination_accounts_for_notes_and_allocation_text(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Packing Pagination Test', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-PAGE-001');
        $packageId = DB::table('crm_quote_packages')->insertGetId([
            'quote_id' => $quote->id,
            'package_no' => 'CASE-LONG-01',
            'package_type' => 'wooden_case',
            'package_length' => 120,
            'package_width' => 80,
            'package_height' => 100,
            'net_weight' => 90,
            'gross_weight' => 100,
            'volume_cbm' => 0.96,
            'notes' => str_repeat('Handle with care and keep dry. ', 18),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (range(1, 6) as $index) {
            $item = $quote->items()->create(array_replace($this->itemPayload("Long allocation item {$index}"), [
                'model' => "MODEL-WITH-LONG-NAME-{$index}",
                'sort_order' => $index,
            ]));
            DB::table('crm_quote_package_items')->insert([
                'quote_id' => $quote->id,
                'package_id' => $packageId,
                'quote_item_id' => $item->id,
                'allocated_quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $quote->update(['packing_mode' => 'package_plan', 'packing_status' => 'applied']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.print', ['quoteId' => $quote->id, 'type' => 'packing_list', 'language' => 'en']))
            ->assertOk()
            ->assertSee('CASE-LONG-01')
            ->assertSee('Page 1 of 2');
    }

    public function test_document_copy_keeps_items_but_not_the_source_package_plan(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Packing Copy Test', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-COPY-001');
        $quote->items()->create($this->itemPayload('Copied machine'));
        $quote->update([
            'packing_mode' => 'package_plan',
            'packing_status' => 'applied',
            'packing_applied_at' => now(),
            'packing_applied_by_admin_id' => $admin->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.quotes.convert', ['quoteId' => $quote->id]), ['document_type' => 'proforma_invoice'])
            ->assertRedirect();

        $copy = CrmQuote::query()->where('source_quote_id', $quote->id)->latest('id')->firstOrFail();
        $this->assertSame('item_level', $copy->packing_mode);
        $this->assertSame('draft', $copy->packing_status);
        $this->assertNull($copy->packing_applied_at);
        $this->assertNull($copy->packing_applied_by_admin_id);
        $this->assertSame(0, $copy->packages()->count());
        $this->assertSame(1, $copy->items()->count());
    }

    public function test_duplicate_item_inside_one_package_is_rejected_without_database_error(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Packing Duplicate Allocation', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-DUP-ALLOC-001');
        $item = $quote->items()->create($this->itemPayload('Machine'));

        $this->actingAs($admin, 'admin')
            ->from(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->put(route('admin.crm.quotes.update', ['quoteId' => $quote->id]), array_replace(
                $this->baseUpdatePayload($quote, $customer),
                [
                    'packing_action' => 'save',
                    'items' => $this->itemsRequest([$item]),
                    'packages' => [$this->packagePayload('CASE-01', [
                        ['quote_item_id' => $item->id, 'allocated_quantity' => 0.5],
                        ['quote_item_id' => $item->id, 'allocated_quantity' => 0.5],
                    ])],
                ],
            ))
            ->assertRedirect(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->assertSessionHasErrors('packages');

        $this->assertSame(0, $quote->packages()->count());
    }

    public function test_nested_package_validation_errors_reopen_the_workbench(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Packing Nested Validation', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-NESTED-001');
        $item = $quote->items()->create($this->itemPayload('Machine'));
        $package = $this->packagePayload('CASE-01', [
            ['quote_item_id' => $item->id, 'allocated_quantity' => 1],
        ]);
        unset($package['package_width']);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->followingRedirects()
            ->put(route('admin.crm.quotes.update', ['quoteId' => $quote->id]), array_replace(
                $this->baseUpdatePayload($quote, $customer),
                [
                    'packing_action' => 'save',
                    'items' => $this->itemsRequest([$item]),
                    'packages' => [$package],
                ],
            ))
            ->assertOk()
            ->assertSee('data-packing-errors', false)
            ->assertSee('<details open', false)
            ->assertSee('包装方案尚未保存');
    }

    public function test_packing_exempt_item_cannot_be_allocated_to_a_package(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Packing Exempt Allocation', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-EXEMPT-001');
        $service = $quote->items()->create(array_replace($this->itemPayload('Remote training'), [
            'line_type' => 'service',
            'packing_exempt' => true,
        ]));
        $items = $this->itemsRequest([$service]);
        $items['packing_exempt'] = [1];

        $this->actingAs($admin, 'admin')
            ->from(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->put(route('admin.crm.quotes.update', ['quoteId' => $quote->id]), array_replace(
                $this->baseUpdatePayload($quote, $customer),
                [
                    'packing_action' => 'apply',
                    'items' => $items,
                    'packages' => [$this->packagePayload('CASE-01', [
                        ['quote_item_id' => $service->id, 'allocated_quantity' => 1],
                    ])],
                ],
            ))
            ->assertRedirect(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->assertSessionHasErrors('packages');

        $this->assertSame(0, $quote->packages()->count());
    }

    public function test_user_can_switch_back_to_item_level_packing_without_deleting_package_draft(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Packing Mode Switch', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-MODE-001');
        $item = $quote->items()->create($this->itemPayload('Machine'));
        $packageId = DB::table('crm_quote_packages')->insertGetId([
            'quote_id' => $quote->id,
            'package_no' => 'CASE-01',
            'package_type' => 'wooden_case',
            'package_length' => 100,
            'package_width' => 80,
            'package_height' => 60,
            'net_weight' => 90,
            'gross_weight' => 100,
            'volume_cbm' => 0.48,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('crm_quote_package_items')->insert([
            'quote_id' => $quote->id,
            'package_id' => $packageId,
            'quote_item_id' => $item->id,
            'allocated_quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $quote->update(['packing_mode' => 'package_plan', 'packing_status' => 'applied']);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.crm.quotes.update', ['quoteId' => $quote->id]), array_replace(
                $this->baseUpdatePayload($quote, $customer),
                ['packing_action' => 'item_level'],
            ))
            ->assertRedirect(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]));

        $quote->refresh();
        $this->assertSame('item_level', $quote->packing_mode);
        $this->assertSame('draft', $quote->packing_status);
        $this->assertNull($quote->packing_applied_at);
        $this->assertSame(1, $quote->packages()->count());
    }

    public function test_saved_quote_item_quantity_must_be_greater_than_zero(): void
    {
        $admin = $this->admin();
        $customer = CrmCustomer::query()->create(['company_name' => 'Packing Quantity Boundary', 'status' => 'active']);
        $quote = $this->quote($customer, 'PACK-QTY-001');
        $item = $quote->items()->create($this->itemPayload('Machine'));
        $items = $this->itemsRequest([$item]);
        $items['quantity'] = [0];

        $this->actingAs($admin, 'admin')
            ->from(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->put(route('admin.crm.quotes.update', ['quoteId' => $quote->id]), array_replace(
                $this->baseUpdatePayload($quote, $customer),
                ['items' => $items],
            ))
            ->assertRedirect(route('admin.crm.quotes.edit', ['quoteId' => $quote->id]))
            ->assertSessionHasErrors('items.quantity.0');

        $this->assertEqualsWithDelta(1.0, (float) $item->fresh()->quantity, 0.001);
    }

    private function quote(CrmCustomer $customer, string $quoteNo): CrmQuote
    {
        return CrmQuote::query()->create([
            'customer_id' => $customer->id,
            'quote_no' => $quoteNo,
            'document_type' => 'packing_list',
            'title' => $quoteNo,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
    }

    /** @return array<string, mixed> */
    private function itemPayload(string $name): array
    {
        return [
            'item_name' => $name,
            'quantity' => 1,
            'unit' => 'set',
            'unit_price' => 100,
            'amount' => 100,
            'sort_order' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function baseUpdatePayload(CrmQuote $quote, CrmCustomer $customer): array
    {
        return [
            'customer_id' => $customer->id,
            'quote_no' => $quote->quote_no,
            'document_type' => 'packing_list',
            'title' => $quote->title,
            'currency' => 'USD',
            'status' => 'draft',
        ];
    }

    /** @param array<int, CrmQuoteItem> $items */
    private function itemsRequest(array $items): array
    {
        return [
            'id' => array_map(static fn ($item): int => (int) $item->id, $items),
            'item_name' => array_map(static fn ($item): string => (string) $item->item_name, $items),
            'quantity' => array_map(static fn ($item): string => (string) $item->quantity, $items),
            'unit' => array_map(static fn ($item): string => (string) $item->unit, $items),
            'unit_price' => array_map(static fn ($item): string => (string) $item->unit_price, $items),
            'packing_exempt' => array_fill(0, count($items), 0),
        ];
    }

    /** @param list<array{quote_item_id:int,allocated_quantity:int|float}> $allocations */
    private function packagePayload(string $packageNo, array $allocations): array
    {
        return [
            'package_no' => $packageNo,
            'package_type' => 'wooden_case',
            'package_length' => 100,
            'package_width' => 80,
            'package_height' => 60,
            'net_weight' => 90,
            'gross_weight' => 100,
            'volume_cbm' => 0.48,
            'volume_is_manual' => 0,
            'notes' => '',
            'allocations' => $allocations,
        ];
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'packing_admin',
            'password' => 'secret-123',
            'email' => 'packing-admin@example.com',
            'display_name' => 'Packing Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
