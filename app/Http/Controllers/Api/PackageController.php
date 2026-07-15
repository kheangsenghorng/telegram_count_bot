<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Package\PackageStoreRequest;
use App\Http\Requests\Package\PackageUpdateRequest;
use App\Http\Resources\Package\PackageResource;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class PackageController extends Controller
{
    private const CACHE_KEY_ACTIVE_PACKAGES = 'packages:active';

    private const DEFAULT_PER_PAGE = 10;

    private const MAX_PER_PAGE = 100;

    /**
     * Display a paginated list of packages.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(
            max((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE
        );

        $packages = Package::query()
            ->when(
                $request->filled('search'),
                function ($query) use ($request): void {
                    $search = trim((string) $request->input('search'));

                    $query->where(function ($query) use ($search): void {
                        $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
                }
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where(
                    'status',
                    $request->string('status')->toString()
                )
            )
            ->when(
                $request->filled('billing_cycle'),
                fn ($query) => $query->where(
                    'billing_cycle',
                    $request->string('billing_cycle')->toString()
                )
            )
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => PackageResource::collection($packages),
            'meta' => [
                'current_page' => $packages->currentPage(),
                'last_page' => $packages->lastPage(),
                'per_page' => $packages->perPage(),
                'total' => $packages->total(),
            ],
            'links' => [
                'first' => $packages->url(1),
                'last' => $packages->url($packages->lastPage()),
                'previous' => $packages->previousPageUrl(),
                'next' => $packages->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Create a new package.
     */
    public function store(PackageStoreRequest $request): JsonResponse
    {
        $package = Package::query()->create(
            $request->validated()
        );

        $this->clearPackageCache();

        return response()->json([
            'success' => true,
            'message' => 'Package created successfully.',
            'data' => new PackageResource($package),
        ], JsonResponse::HTTP_CREATED);
    }

    /**
     * Display a package.
     */
    public function show(Package $package): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new PackageResource($package),
        ]);
    }

    /**
     * Update a package.
     */
    public function update(
        PackageUpdateRequest $request,
        Package $package
    ): JsonResponse {
        $package->update(
            $request->validated()
        );

        $this->clearPackageCache();

        return response()->json([
            'success' => true,
            'message' => 'Package updated successfully.',
            'data' => new PackageResource($package->refresh()),
        ]);
    }

    /**
     * Delete a package.
     */
    public function destroy(Package $package): JsonResponse
    {
        $package->delete();

        $this->clearPackageCache();

        return response()->json([
            'success' => true,
            'message' => 'Package deleted successfully.',
        ]);
    }

    /**
     * Clear cached package data after create, update, or delete.
     */
    private function clearPackageCache(): void
    {
        Cache::forget(self::CACHE_KEY_ACTIVE_PACKAGES);
    }
}