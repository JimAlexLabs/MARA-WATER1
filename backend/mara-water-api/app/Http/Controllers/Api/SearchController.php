<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\User;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\Sku;
use App\Models\Vehicle;

class SearchController extends Controller
{
    /**
     * Global top-bar search across the entities the app has record pages for.
     * Each result carries a `route` the frontend navigates to on click.
     */
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $results = [];

        Customer::where('name', 'like', $like)
            ->orWhere('code', 'like', $like)
            ->orWhere('phone', 'like', $like)
            ->limit(5)->get()
            ->each(function ($c) use (&$results) {
                $results[] = [
                    'type' => 'customer',
                    'label' => $c->name,
                    'sublabel' => trim(($c->code ?? '') . ' · ' . ($c->phone ?? ''), ' ·'),
                    'route' => '/sales?tab=customers&q=' . urlencode($c->name),
                ];
            });

        User::where('first_name', 'like', $like)
            ->orWhere('last_name', 'like', $like)
            ->orWhere('email', 'like', $like)
            ->limit(5)->get()
            ->each(function ($u) use (&$results) {
                $results[] = [
                    'type' => 'employee',
                    'label' => trim($u->first_name . ' ' . $u->last_name),
                    'sublabel' => $u->email,
                    'route' => '/users?q=' . urlencode($u->email),
                ];
            });

        Order::where('order_no', 'like', $like)
            ->limit(5)->get()
            ->each(function ($o) use (&$results) {
                $results[] = [
                    'type' => 'order',
                    'label' => $o->order_no,
                    'sublabel' => 'Order · ' . $o->status,
                    'route' => '/sales?tab=orders&q=' . urlencode($o->order_no),
                ];
            });

        Invoice::where('invoice_no', 'like', $like)
            ->limit(5)->get()
            ->each(function ($i) use (&$results) {
                $results[] = [
                    'type' => 'invoice',
                    'label' => $i->invoice_no,
                    'sublabel' => 'Invoice · ' . $i->status,
                    'route' => '/finance?q=' . urlencode($i->invoice_no),
                ];
            });

        Sku::where('name', 'like', $like)
            ->orWhere('code', 'like', $like)
            ->limit(5)->get()
            ->each(function ($s) use (&$results) {
                $results[] = [
                    'type' => 'product',
                    'label' => $s->name,
                    'sublabel' => $s->code,
                    'route' => '/inventory?q=' . urlencode($s->name),
                ];
            });

        Vehicle::where('reg_no', 'like', $like)
            ->orWhere('make', 'like', $like)
            ->orWhere('model', 'like', $like)
            ->limit(5)->get()
            ->each(function ($v) use (&$results) {
                $results[] = [
                    'type' => 'vehicle',
                    'label' => $v->reg_no,
                    'sublabel' => trim(($v->make ?? '') . ' ' . ($v->model ?? '')),
                    'route' => '/fleet?q=' . urlencode($v->reg_no),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => array_slice($results, 0, 20),
        ]);
    }
}
