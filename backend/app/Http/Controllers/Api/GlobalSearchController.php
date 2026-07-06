<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceUnit;
use App\Models\Doctor;
use App\Models\Hospital;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Universal search across doctors, hospitals, the stock catalog (name /
 * catalogue no / item code), device serial & lot numbers, and reps.
 */
class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (strlen($term) < 2) {
            return response()->json(['query' => $term, 'groups' => []]);
        }

        $like = '%'.$term.'%';
        $limit = 8;

        $groups = [];

        if ($request->user()->can('doctor.view')) {
            $groups['doctors'] = Doctor::where('name', 'like', $like)
                ->orWhere('specialty', 'like', $like)
                ->limit($limit)->get()
                ->map(fn ($d) => [
                    'id' => $d->id, 'title' => $d->name,
                    'subtitle' => \Illuminate\Support\Str::headline((string) $d->specialty),
                    'link' => "/doctors/{$d->id}",
                ]);
        }

        if ($request->user()->can('hospital.view')) {
            $groups['hospitals'] = Hospital::where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->limit($limit)->get()
                ->map(fn ($h) => [
                    'id' => $h->id, 'title' => $h->name,
                    'subtitle' => \Illuminate\Support\Str::headline((string) $h->category),
                    'link' => "/hospitals/{$h->id}",
                ]);
        }

        if ($request->user()->can('inventory.view')) {
            $groups['stock'] = StockItem::search($term)
                ->limit($limit)->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'title' => $i->name,
                    'subtitle' => 'Cat '.($i->catalogue_number ?? '—'),
                    'link' => "/inventory?item={$i->id}",
                ]);

            $groups['devices'] = DeviceUnit::with(['stockItem:id,name', 'location:id,name'])
                ->where(fn ($q) => $q->where('serial_number', 'like', $like)->orWhere('lot_number', 'like', $like))
                ->limit($limit)->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'title' => ($u->stockItem?->name ?? 'Device').' · SN '.($u->serial_number ?? '—'),
                    'subtitle' => 'Lot '.($u->lot_number ?? '—').' · '.($u->location?->name ?? '—'),
                    'link' => "/inventory?item={$u->stock_item_id}",
                ]);
        }

        $groups['reps'] = User::where('name', 'like', $like)
            ->orWhere('email', 'like', $like)
            ->limit($limit)->get()
            ->map(fn ($u) => [
                'id' => $u->id, 'title' => $u->name,
                'subtitle' => \Illuminate\Support\Str::headline((string) $u->staff_type),
                'link' => $u->location_id ? "/inventory?location={$u->location_id}" : '/users',
            ]);

        return response()->json([
            'query'  => $term,
            'groups' => array_filter($groups, fn ($g) => $g->isNotEmpty()),
        ]);
    }
}
