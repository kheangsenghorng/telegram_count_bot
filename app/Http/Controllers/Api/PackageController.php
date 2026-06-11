<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Package\PackageStoreRequest;
use App\Http\Requests\Package\PackageUpdateRequest;
use App\Http\Resources\Package\PackageResource;
use App\Models\Package;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => PackageResource::collection(
                Package::latest()->paginate(10)
            ),
        ]);
    }

    public function store(PackageStoreRequest $request)
    {
        $package = Package::create($request->validated());
    
        return response()->json([
            'success' => true,
            'message' => 'Package created successfully',
            'data' => new PackageResource($package),
        ], 201);
    }
    public function show(Package $package)
    {
        return response()->json([
            'success' => true,
            'data' => new PackageResource($package),
        ]);
    }
    public function update(
        PackageUpdateRequest $request,
        Package $package
    ) {
        $package->update($request->validated());
    
        return response()->json([
            'success' => true,
            'message' => 'Package updated successfully',
            'data' => new PackageResource($package),
        ]);
    }

    public function destroy(Package $package)
    {
        $package->delete();

        return response()->json([
            'success' => true,
            'message' => 'Package deleted successfully'
        ]);
    }
}