<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LogoController extends Controller
{
    /**
     * Store or update logo for a client
     */
    public function storeClient(Request $request, Client $client)
    {
        $request->validate([
            'logo' => 'required|file|mimes:svg,png,jpg,jpeg|max:2048',
        ]);

        // Delete old logo if exists
        if ($client->logo) {
            $oldPath = public_path('storage/' . $client->logo);
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        // Store new logo
        $file = $request->file('logo');
        $path = $file->store('logos', 'public');
        
        // Create symlink if needed (for shared hosting)
        $this->ensureSymlink();

        // Update client
        $client->update(['logo' => $path]);

        return response()->json([
            'success' => true,
            'logo_url' => asset('storage/' . $path),
        ]);
    }

    /**
     * Store or update logo for a supplier
     */
    public function storeSupplier(Request $request, Supplier $supplier)
    {
        $request->validate([
            'logo' => 'required|file|mimes:svg,png,jpg,jpeg|max:2048',
        ]);

        // Delete old logo if exists
        if ($supplier->logo) {
            $oldPath = public_path('storage/' . $supplier->logo);
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        // Store new logo
        $file = $request->file('logo');
        $path = $file->store('logos', 'public');
        
        // Create symlink if needed (for shared hosting)
        $this->ensureSymlink();

        // Update supplier
        $supplier->update(['logo' => $path]);

        return response()->json([
            'success' => true,
            'logo_url' => asset('storage/' . $path),
        ]);
    }

    /**
     * Delete logo for a client
     */
    public function destroyClient(Client $client)
    {
        if ($client->logo) {
            $path = public_path('storage/' . $client->logo);
            if (file_exists($path)) {
                unlink($path);
            }
            $client->update(['logo' => null]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Delete logo for a supplier
     */
    public function destroySupplier(Supplier $supplier)
    {
        if ($supplier->logo) {
            $path = public_path('storage/' . $supplier->logo);
            if (file_exists($path)) {
                unlink($path);
            }
            $supplier->update(['logo' => null]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Ensure storage symlink exists
     */
    protected function ensureSymlink()
    {
        $link = public_path('storage');
        $target = storage_path('app/public');
        
        if (!file_exists($link) && !is_link($link)) {
            symlink($target, $link);
        }
    }
}
