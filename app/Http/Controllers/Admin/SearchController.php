<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ServiceInstance;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function search(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $results = [];
        if (strlen($q) >= 2) {
            $results['customers'] = Customer::whereHas('user', function ($query) use ($q) {
                $query->where('email', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%");
            })
                ->orWhere('company', 'like', "%{$q}%")
                ->with('user')
                ->limit(5)->get();
            $results['services'] = ServiceInstance::where('username', 'like', "%{$q}%")
                ->orWhere('domain', 'like', "%{$q}%")
                ->with('customer')
                ->limit(5)->get();
            $results['invoices'] = Invoice::where('invoice_no', 'like', "%{$q}%")
                ->limit(5)->get();
            $results['tickets'] = Ticket::where('subject', 'like', "%{$q}%")
                ->orWhere('ticket_no', 'like', "%{$q}%")
                ->limit(5)->get();
            $results['products'] = CatalogProduct::where('name', 'like', "%{$q}%")
                ->orWhere('sku', 'like', "%{$q}%")
                ->limit(5)->get();
        }

        return view('admin.search.index', compact('q', 'results'));
    }
}
