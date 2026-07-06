<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unit-level inventory remodel.
 *
 * Introduces:
 *  - locations     : every place stock can sit (hospital / boot / office) —
 *                    the unified "entity" list used for transfers.
 *  - stock_items   : the product catalog (name, catalogue number, item code).
 *  - device_units  : one row per physical device (serial, lot, expiry) at a
 *                    location. Transfers move units, not quantities.
 *
 * Also converts any legacy quantity-based inventory_items rows into units so
 * no stock is lost. The legacy table is kept (read-only history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique()->nullable();
            $table->string('type'); // hospital | boot | office | warehouse | other
            $table->foreignId('hospital_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete(); // boot owner
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('catalogue_number')->nullable();
            $table->string('item_code')->nullable();
            $table->text('description')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->integer('min_threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('catalogue_number');
            $table->index('item_code');
        });

        Schema::create('device_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->string('serial_number')->nullable();
            $table->string('lot_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            // available | pending_transfer | missing | used | expired | archived
            $table->string('status')->default('available');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('serial_number');
            $table->index('lot_number');
            $table->index('status');
            $table->index(['location_id', 'status']);
        });

        // Every user is linked to the location (boot/office) whose stock is "My Inventory".
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->nullOnDelete();
        });

        Schema::table('transfer_items', function (Blueprint $table) {
            $table->foreignId('device_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_number')->nullable();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('device_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->nullOnDelete();
        });

        Schema::table('stock_counts', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();
        });

        $this->convertLegacyInventory();
    }

    /** Convert quantity-based inventory_items rows into catalog + units. */
    protected function convertLegacyInventory(): void
    {
        if (! Schema::hasTable('inventory_items')) {
            return;
        }

        $rows = DB::table('inventory_items')->whereNull('deleted_at')->where('quantity', '>', 0)->get();

        foreach ($rows as $row) {
            $locationId = $this->resolveLocation($row);
            if (! $locationId) {
                continue;
            }

            $itemId = DB::table('stock_items')->where('catalogue_number', $row->ref_code)->value('id')
                ?? DB::table('stock_items')->insertGetId([
                    'name'             => $row->description,
                    'catalogue_number' => $row->ref_code,
                    'item_code'        => $row->barcode,
                    'uom'              => $row->uom,
                    'unit_price'       => $row->unit_price,
                    'min_threshold'    => $row->min_threshold,
                    'is_active'        => true,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);

            $units = [];
            for ($i = 0; $i < (int) $row->quantity; $i++) {
                $units[] = [
                    'stock_item_id' => $itemId,
                    'serial_number' => null, // legacy stock had no serials
                    'lot_number'    => $row->lot_number,
                    'expiry_date'   => $row->expiry_date,
                    'location_id'   => $locationId,
                    'status'        => 'available',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
            }
            DB::table('device_units')->insert($units);
        }
    }

    protected function resolveLocation(object $row): ?int
    {
        [$name, $type, $hospitalId, $ownerId] = match (true) {
            $row->location === 'boot_stock' && $row->holder_user_id !== null => [
                (DB::table('users')->where('id', $row->holder_user_id)->value('name') ?? 'Rep').' Boot',
                'boot', null, $row->holder_user_id,
            ],
            $row->location === 'hospital_stock' && $row->hospital_id !== null => [
                DB::table('hospitals')->where('id', $row->hospital_id)->value('name') ?? 'Hospital',
                'hospital', $row->hospital_id, null,
            ],
            $row->location === 'jhb_master_warehouse' => ['JHB Office', 'office', null, null],
            $row->location === 'durban_master_warehouse' => ['Durban Office', 'office', null, null],
            default => ['Unallocated', 'other', null, null],
        };

        $existing = DB::table('locations')->where('name', $name)->value('id');
        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('locations')->insertGetId([
            'name'          => $name,
            'type'          => $type,
            'hospital_id'   => $hospitalId,
            'owner_user_id' => $ownerId,
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('stock_count_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('stock_item_id'));
        Schema::table('stock_counts', fn (Blueprint $t) => $t->dropConstrainedForeignId('location_id'));
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('device_unit_id');
            $t->dropConstrainedForeignId('from_location_id');
            $t->dropConstrainedForeignId('to_location_id');
        });
        Schema::table('transfer_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('device_unit_id');
            $t->dropColumn('serial_number');
        });
        Schema::table('transfers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('from_location_id');
            $t->dropConstrainedForeignId('to_location_id');
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('location_id'));
        Schema::dropIfExists('device_units');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('locations');
    }
};
