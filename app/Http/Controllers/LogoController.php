<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\CompanyProfile;
use App\Models\Supplier;
use App\Services\IfrsPosting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            Storage::disk('public')->delete($client->logo);
        }

        // Store new logo
        $file = $request->file('logo');
        $path = $file->store('logos', 'public');

        $this->ensureSymlink();

        // Update client
        $client->update(['logo' => $path]);

        return response()->json([
            'success' => true,
            'logo_url' => asset('storage/'.$path),
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
            Storage::disk('public')->delete($supplier->logo);
        }

        // Store new logo
        $file = $request->file('logo');
        $path = $file->store('logos', 'public');

        $this->ensureSymlink();

        // Update supplier
        $supplier->update(['logo' => $path]);

        return response()->json([
            'success' => true,
            'logo_url' => asset('storage/'.$path),
        ]);
    }

    /**
     * Store or update the company logo (SVG or PNG). Unlike the client
     * and supplier uploaders this renders into the server-side Company
     * Details page, so it redirects with a flash instead of answering
     * JSON.
     */
    public function storeCompany(Request $request)
    {
        $request->validate([
            'logo' => 'required|file|mimes:svg,png|max:2048',
        ]);

        $profile = $this->companyProfile();

        if ($profile->logo) {
            Storage::disk('public')->delete($profile->logo);
        }

        $path = $request->file('logo')->store('company-logos', 'public');

        $this->ensureSymlink();

        // save() inserts for the first logo (the profile row may not
        // exist yet — Model::update() is a no-op on unsaved instances).
        $profile->logo = $path;
        $profile->save();

        return redirect()->route('company-profile.index')
            ->with('success', 'Company logo updated.');
    }

    /**
     * Delete the company logo
     */
    public function destroyCompany()
    {
        $profile = $this->companyProfile();

        if ($profile->logo) {
            Storage::disk('public')->delete($profile->logo);
            $profile->update(['logo' => null]);
        }

        return redirect()->route('company-profile.index')
            ->with('success', 'Company logo removed.');
    }

    /**
     * Delete logo for a client
     */
    public function destroyClient(Client $client)
    {
        if ($client->logo) {
            Storage::disk('public')->delete($client->logo);
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
            Storage::disk('public')->delete($supplier->logo);
            $supplier->update(['logo' => null]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * The reporting entity's company profile row (firstOrNew so a logo
     * can be uploaded before any other detail was ever saved).
     */
    protected function companyProfile(): CompanyProfile
    {
        $entity = IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 404, 'No IFRS entity configured.');

        return CompanyProfile::firstOrNew(['entity_id' => $entity->id]);
    }

    /**
     * The public disk serves through public/storage; create the link when
     * the deployment step hasn't run (php artisan storage:link). Best
     * effort: the web user often cannot write public/, and Laravel turns
     * symlink()'s warning into an exception — a failure here must never
     * take the upload down (same guard as ProfileController).
     */
    protected function ensureSymlink(): void
    {
        $link = public_path('storage');

        if (is_link($link) || file_exists($link)) {
            return;
        }

        try {
            symlink(storage_path('app/public'), $link);
        } catch (\ErrorException $e) {
            Log::warning('Could not create the public/storage symlink — run "php artisan storage:link".', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
