<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Hospital;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Universal search across doctors, hospitals, inventory (ref / lot /
 * description), reps and product descriptions. Returns grouped, link-ready
 * results so the SPA can jump straight to the record.
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
            $groups['inventory'] = InventoryItem::search($term)
                ->limit($limit)->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'title' => "{$i->ref_code} — {$i->description}",
                    'subtitle' => 'Lot '.($i->lot_number ?? '—').' · Qty '.$i->quantity,
                    'link' => "/inventory/{$i->id}",
                ]);
        }

        // Reps / runners.
        $groups['reps'] = User::where('name', 'like', $like)
            ->orWhere('email', 'like', $like)
            ->limit($limit)->get()
            ->map(fn ($u) => [
                'id' => $u->id, 'title' => $u->name,
                'subtitle' => \Illuminate\Support\Str::headline((string) $u->staff_type),
                'link' => "/users/{$u->id}",
            ]);

        return response()->json([
            'query'  => $term,
            'groups' => array_filter($groups, fn ($g) => $g->isNotEmpty()),
        ]);
    }
}
