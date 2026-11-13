<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CreateStoreRequest;
use App\Http\Requests\Store\UpdateStoreRequest;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\StoreSubscription;
use App\Models\SubscriptionPlan;
use App\Support\CanonicalTaxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $stores = $request->user()->stores()->with('subscriptions.plan')->get();

        return response()->json([
            'success' => true,
            'data' => $stores,
        ]);
    }

    public function store(CreateStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['owner_id'] = $request->user()->id;
        $validated['slug'] = Str::slug($validated['name']).'-'.uniqid();

        $store = Store::create($validated);

        // Auto-assign the Premium plan as the default subscription for new stores.
        // In production this would be gated behind a real payment step.
        $premiumPlan = SubscriptionPlan::where('slug', 'premium')
            ->where('is_active', true)
            ->first();

        if ($premiumPlan) {
            StoreSubscription::create([
                'store_id' => $store->id,
                'plan_id' => $premiumPlan->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Store registered. Awaiting admin approval.',
            'data' => $store,
        ], 201);
    }

    public function show(Request $request, Store $store): JsonResponse
    {
        if ($request->user()->id !== $store->owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this store.',
            ], 403);
        }

        // Not currently rendered by any owner-side page, but publicProfile()
        // computes these for anyone viewing the store publicly — an owner's
        // own authenticated view of their own store shouldn't return less
        // data about it than a random visitor gets. Found while comparing
        // storefront-preview vs owner-dashboard consistency.
        $store->loadCount('reviews');
        $store->loadAvg('reviews', 'rating');
        $store->reviews_avg_rating = round($store->reviews_avg_rating, 1);

        return response()->json([
            'success' => true,
            'data' => $store->load(['owner', 'subscriptions.plan']),
        ]);
    }

    /**
     * Public store discovery/search/map feed — the endpoint Objectives 3/4
     * (Store Discovery + Map-Based Navigation) needed but never had. Unlike
     * publicProfile() below, this one also checks `status = 'approved'` —
     * publicProfile() only ever checked is_hidden, which technically leaves
     * a pending/unapproved store publicly reachable by direct slug lookup.
     * A listing surface shouldn't repeat that gap.
     */
    /**
     * Km from the customer to the nearest ACTIVE branch of the shop that owns the row. One expression
     * reused for the select, the radius filter and the sort: Postgres (unlike MySQL) does not let WHERE /
     * HAVING / an ORDER BY expression refer to a SELECT alias. The cosine is clamped to [-1, 1] because
     * Postgres raises an error for ACOS() of a value that rounding pushes just past 1.
     */
    private static function distanceSql(string $shopIdColumn, bool $inDistrict = false): string
    {
        // $inDistrict: only the shop's branches in the filtered district count (one extra binding),
        // so "Talomo" measures to the Talomo branch, not to a nearer branch elsewhere.
        return '(SELECT MIN(6371 * ACOS(LEAST(1.0, GREATEST(-1.0, '
            .'COS(RADIANS(?)) * COS(RADIANS(store_branches.latitude)) * COS(RADIANS(store_branches.longitude) - RADIANS(?)) '
            .'+ SIN(RADIANS(?)) * SIN(RADIANS(store_branches.latitude)))))) '
            .'FROM store_branches WHERE store_branches.store_id = '.$shopIdColumn." AND store_branches.status = 'active' AND store_branches.verification_status = 'verified'"
            .($inDistrict ? ' AND store_branches.district = ?' : '').')';
    }

    /**
     * The uniform types customers can narrow "Uniform" by: each type with how many listed shops
     * have an active service of it. Types no shop offers yet are left out.
     */
    public function publicUniformTypes(): JsonResponse
    {
        $counts = \App\Models\Service::query()
            ->where('is_active', true)
            ->where('service_category', 'uniform_production')
            ->whereIn('service_leaf_type', CanonicalTaxonomy::UNIFORM_TYPES)
            ->whereHas('store', fn ($q) => $q->where('is_hidden', false)->where('status', 'approved'))
            ->selectRaw('service_leaf_type, COUNT(DISTINCT store_id) as shops')
            ->groupBy('service_leaf_type')
            ->pluck('shops', 'service_leaf_type');

        $data = collect(CanonicalTaxonomy::UNIFORM_TYPES)
            ->filter(fn ($type) => ($counts[$type] ?? 0) > 0)
            ->map(fn ($type) => ['value' => $type, 'label' => CanonicalTaxonomy::SERVICE_TYPE_LABELS[$type] ?? $type, 'shops' => (int) $counts[$type]])
            ->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function publicIndex(Request $request): JsonResponse
    {
        $query = Store::query()
            ->where('is_hidden', false)
            ->where('status', 'approved')
            // SUTURA's own scope is Davao City — a server-side guarantee,
            // not just a coincidence of the current seed data all happening
            // to be Davao-based, so a future out-of-scope registration
            // can't silently show up on the public discovery map/search.
            ->where(function ($q) {
                $q->where('city', 'like', '%Davao%')
                    ->orWhere('province', 'like', '%Davao del Sur%');
            });

        if ($request->filled('q')) {
            $search = strtolower((string) $request->string('q'));
            // The search with spaces, "&", "." and "-" taken out, so "AA" also
            // finds "A & A Tailoring" and "A.A. Tailoring".
            $compact = str_replace([' ', '&', '.', '-'], '', $search);
            $squash = "REPLACE(REPLACE(REPLACE(REPLACE(LOWER(%s), ' ', ''), '&', ''), '.', ''), '-', '')";
            $query->where(function ($q) use ($search, $compact, $squash) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                    ->when($compact !== '', fn ($qq) => $qq->orWhereRaw(sprintf($squash, 'name').' LIKE ?', ['%'.$compact.'%']))
                    ->orWhereRaw('LOWER(specializations) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('LOWER(custom_services) LIKE ?', ['%'.$search.'%'])
                    // A branch's own name ("AA Tailoring II"); "Ubalde" finds shops
                    // in that barangay; "Jasmin" finds the street.
                    ->orWhereHas('branches', fn ($bq) => $bq->where(fn ($b) => $b
                        ->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                        ->when($compact !== '', fn ($bb) => $bb->orWhereRaw(sprintf($squash, 'name').' LIKE ?', ['%'.$compact.'%']))
                        ->orWhereRaw('LOWER(barangay) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(address) LIKE ?', ['%'.$search.'%'])))
                    ->orWhereHas('services', function ($sq) use ($search) {
                        $sq->whereRaw('LOWER(category) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(name) LIKE ?', ['%'.$search.'%']);
                    })
                    ->orWhereHas('catalogItems', function ($cq) use ($search) {
                        $cq->listed()
                            ->where(function ($ccq) use ($search) {
                                $ccq->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                                    ->orWhereRaw('LOWER(garment_type) LIKE ?', ['%'.$search.'%'])
                                    ->orWhereRaw('LOWER(material) LIKE ?', ['%'.$search.'%'])
                                    ->orWhereRaw('LOWER(description) LIKE ?', ['%'.$search.'%']);
                            });
                    });
            });
        }

        // Header nav's MEN/WOMEN/KIDS/SERVICES axis — Store itself has no
        // department column, so this filters to stores that actually carry
        // something in that department: a catalog item tagged with it for
        // men/women/children, or (a store has no equivalent column for
        // "does services" other than having any) any active service at all
        // for 'services'.
        // Landing "Featured & Recommended": only shops on an active Premium plan.
        if ($request->boolean('premium_only')) {
            $query->premiumActive();
        }

        if ($request->filled('department')) {
            $department = strtolower(trim((string) $request->string('department')));
            if ($department === 'services' || $department === 'service') {
                $query->whereHas('services', fn ($sq) => $sq->where('is_active', true));
            } else {
                $query->whereHas('catalogItems', function ($cq) use ($department) {
                    $cq->listed()->whereRaw('LOWER(department) = ?', [$department]);
                });
            }
        }

        // Under "Uniform": only shops with an active service of this uniform type (PNP, BFP, school…).
        if ($request->filled('uniform_type') && in_array($request->input('uniform_type'), CanonicalTaxonomy::UNIFORM_TYPES, true)) {
            $query->whereHas('services', fn ($sq) => $sq->where('is_active', true)
                ->where('service_category', 'uniform_production')
                ->where('service_leaf_type', $request->input('uniform_type')));
        }

        $spec = $request->input('specialization') ?: $request->input('category');
        if ($spec) {
            $query->where(function ($sq) use ($spec) {
                // Specializations are now the canonical Men/Women/Kids +
                // Services axes (CanonicalTaxonomy::storeSpecializations()),
                // so also match a store by what it actually carries — a
                // catalog item in that department, or a service in that
                // service category — not only by its self-declared tags.
                $sq->whereJsonContains('specializations', (string) $spec)
                    ->orWhereHas('catalogItems', function ($cq) use ($spec) {
                        $cq->listed()
                            ->where(function ($ccq) use ($spec) {
                                $ccq->where('garment_type', (string) $spec)
                                    ->orWhere('department', (string) $spec)
                                    ->orWhereRaw('LOWER(name) LIKE ?', ['%'.strtolower((string) $spec).'%']);
                            });
                    })
                    ->orWhereHas('services', function ($svq) use ($spec) {
                        $svq->where('is_active', true)
                            ->where(function ($ssq) use ($spec) {
                                // "Uniform" (a shop tag) = services in the Uniform Production category.
                                $ssq->where('service_category', $spec === 'uniform' ? 'uniform_production' : (string) $spec)
                                    ->orWhereRaw('LOWER(category) LIKE ?', ['%'.strtolower((string) $spec).'%']);
                            });
                    });
            });
        }

        if ($request->filled('district')) {
            $district = $request->string('district')->toString();
            $query->whereHas('branches', function ($bq) use ($district) {
                $bq->where('district', $district)->live();
            });
        }
        // Province / city / barangay (PSGC codes).
        \App\Support\Psgc::applyLocationFilter($query, $request, 'branches');

        if ($request->boolean('open_now')) {
            // Real-time branch availability, per Objective 3's own wording.
            // Two things this has to get right, both already-known failure
            // modes elsewhere in this codebase:
            // 1. Asia/Manila, not the app's UTC default — Store::getActiveSpecialHoursAttribute()
            //    already carries this exact warning; a bare now() here would
            //    put the day/time boundary up to 8 hours off from the
            //    store's actual local day during Manila's early-morning hours.
            // 2. A store can declare a store-wide special-hours override
            //    (holiday closure, or a special open/close time) via
            //    StoreSpecialHour — checking only the regular weekly
            //    operating_hours would show a specially-closed store as
            //    "open" (or vice versa) on exactly the dates an owner most
            //    needs this to be accurate.
            $day = strtolower(now('Asia/Manila')->format('l'));
            $time = now('Asia/Manila')->format('H:i');
            $today = now('Asia/Manila')->toDateString();

            // Most-recent store-wide (not branch-specific) special-hours row
            // covering today, if any — same "most recently created wins on
            // overlap" tiebreak as Store::getActiveSpecialHoursAttribute().
            $todaySpecial = \DB::table('store_special_hours')
                ->selectRaw('store_id, is_closed, special_open_time, special_close_time, ROW_NUMBER() OVER (PARTITION BY store_id ORDER BY created_at DESC) as rn')
                ->whereNull('store_branch_id')
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today);

            $query->leftJoinSub($todaySpecial, 'today_special', function ($join) {
                $join->on('today_special.store_id', '=', 'stores.id')->where('today_special.rn', '=', 1);
            })
                ->where(function ($q) use ($day, $time) {
                    $q->where(function ($qq) use ($day, $time) {
                        // No special-hours override today — fall back to the
                        // regular weekly schedule. MySQL's ->> operator is
                        // JSON_UNQUOTE(JSON_EXTRACT(...)) shorthand; is_open
                        // compares against the unquoted string since
                        // JSON_UNQUOTE always yields a string, even for a JSON
                        // boolean.
                        $qq->whereNull('today_special.store_id')
                            // Laravel's JSON-path where() compiles to the right syntax on MySQL and Postgres
                            // ($day is one of the seven weekday names, never user input).
                            ->where("stores.operating_hours->{$day}->is_open", true)
                            ->where("stores.operating_hours->{$day}->open", '<=', $time)
                            ->where("stores.operating_hours->{$day}->close", '>', $time);
                    })->orWhere(function ($qq) use ($time) {
                        // Override exists today and it's not a full closure —
                        // check the special hours instead of the regular ones.
                        $qq->where('today_special.is_closed', false)
                            ->where('today_special.special_open_time', '<=', $time)
                            ->where('today_special.special_close_time', '>', $time);
                    });
                });
        }

        // Price range: the shop must offer something IN the range — and when the
        // customer searched ("uniform"), that something must also match the search,
        // so "uniform, ₱0–₱500" doesn't list a shop whose only cheap item is a patch.
        $hasPrice = $request->filled('min_price') || $request->filled('max_price');
        $searchTerm = $request->filled('q') ? strtolower((string) $request->string('q')) : null;
        $inPrice = function ($q, string $column) use ($request) {
            if ($request->filled('min_price')) {
                $q->where($column, '>=', $request->float('min_price'));
            }
            if ($request->filled('max_price')) {
                $q->where($column, '<=', $request->float('max_price'));
            }
        };
        $itemMatchesSearch = function ($cq) use ($searchTerm) {
            if ($searchTerm === null) {
                return;
            }
            $cq->where(fn ($ccq) => $ccq->whereRaw('LOWER(name) LIKE ?', ['%'.$searchTerm.'%'])
                ->orWhereRaw('LOWER(garment_type) LIKE ?', ['%'.$searchTerm.'%'])
                ->orWhereRaw('LOWER(material) LIKE ?', ['%'.$searchTerm.'%'])
                ->orWhereRaw('LOWER(description) LIKE ?', ['%'.$searchTerm.'%']));
        };
        $serviceMatchesSearch = function ($sq) use ($searchTerm) {
            if ($searchTerm === null) {
                return;
            }
            $sq->where(fn ($ssq) => $ssq->whereRaw('LOWER(name) LIKE ?', ['%'.$searchTerm.'%'])
                ->orWhereRaw('LOWER(category) LIKE ?', ['%'.$searchTerm.'%']));
        };

        if ($hasPrice) {
            $query->where(function ($q) use ($inPrice, $itemMatchesSearch, $serviceMatchesSearch) {
                $q->whereHas('services', function ($sq) use ($inPrice, $serviceMatchesSearch) {
                    $sq->where('is_active', true);
                    $inPrice($sq, 'base_price');
                    $serviceMatchesSearch($sq);
                })->orWhereHas('catalogItems', function ($cq) use ($inPrice, $itemMatchesSearch) {
                    $cq->listed();
                    $inPrice($cq, 'price');
                    $itemMatchesSearch($cq);
                });
            });
        }

        $query->withCount('reviews')->withAvg('reviews', 'rating');

        // Real, uncapped totals — the catalogItems/services relations
        // eager-loaded below (for the storefront preview carousel) are
        // capped at take(10)/unlimited-but-preview-only respectively, so a
        // frontend deriving "N catalog designs"/"N services" from that
        // array's own .length silently undercounts once a store has more
        // than the preview cap. This count query is independent of that
        // capped list on purpose — the same fix pattern already applied to
        // notification badges, Home dashboard alerts, and Job list tabs
        // elsewhere in this codebase.
        // Counts follow the price range too, so "N designs" matches what the card shows.
        $query->withCount([
            'catalogItems as catalog_items_count' => function ($q) use ($inPrice) {
                $q->listed();
                $inPrice($q, 'price');
            },
            'services as services_count' => function ($q) use ($inPrice) {
                $q->where('is_active', true);
                $inPrice($q, 'base_price');
            },
        ]);

        if ($searchTerm !== null) {
            $query->withCount([
                'catalogItems as matching_items_count' => function ($cq) use ($inPrice, $itemMatchesSearch) {
                    $cq->listed();
                    $itemMatchesSearch($cq);
                    $inPrice($cq, 'price');
                },
            ]);
        }

        if ($request->filled('min_rating')) {
            // Same AVG as withAvg() above, repeated: a select alias cannot be used in WHERE/HAVING on Postgres.
            $query->whereRaw('(SELECT AVG(r.rating) FROM store_reviews r WHERE r.store_id = stores.id) >= ?', [$request->float('min_rating')]);
        }

        if ($request->filled('lat') && $request->filled('lng')) {
            $lat = $request->float('lat');
            $lng = $request->float('lng');

            // No geospatial package exists anywhere in this codebase — plain
            // Haversine formula against each store's nearest active branch.
            // No "stores.*," prefix here — withCount()/withAvg() above already
            // add it implicitly; repeating it caused a "duplicate column
            // 'id'" error once paginate() wrapped this into a COUNT subquery.
            $district = $request->filled('district') ? $request->string('district')->toString() : null;
            $distanceSql = self::distanceSql('stores.id', $district !== null);
            $distBindings = $district !== null ? [$lat, $lng, $lat, $district] : [$lat, $lng, $lat];
            $query->selectRaw("($distanceSql) as distance_km", $distBindings);

            if ($request->filled('radius_km')) {
                $query->whereRaw("$distanceSql <= ?", [...$distBindings, $request->float('radius_km')]);
            }
        }

        // Price sort: the shop's lowest (Low → High) or highest (High → Low) price among
        // what is shown — listed designs and active services, within the price range.
        $priceBound = function (string $agg) use ($request) {
            // ₱0 means "price on request", not "free" — it never counts as the cheapest.
            $range = ' AND %1$s > 0';
            $bindings = [];
            if ($request->filled('min_price')) {
                $range .= ' AND %1$s >= ?';
                $bindings[] = $request->float('min_price');
            }
            if ($request->filled('max_price')) {
                $range .= ' AND %1$s <= ?';
                $bindings[] = $request->float('max_price');
            }
            // Same rule as CatalogItem::scopeListed(): active, and any custom apparel type approved.
            $items = "(SELECT {$agg}(ci.price) FROM catalog_items ci WHERE ci.store_id = stores.id AND ci.is_active = TRUE"
                ." AND (ci.custom_apparel_type_id IS NULL OR EXISTS (SELECT 1 FROM custom_apparel_types cat WHERE cat.id = ci.custom_apparel_type_id AND cat.status = 'approved'))"
                .sprintf($range, 'ci.price').')';
            $services = "(SELECT {$agg}(sv.base_price) FROM services sv WHERE sv.store_id = stores.id AND sv.is_active = TRUE AND sv.deleted_at IS NULL".sprintf($range, 'sv.base_price').')';

            return [$items, $services, [...$bindings, ...$bindings]];
        };

        $sortBy = $request->string('sort_by')->toString();
        if (! $sortBy) {
            if ($request->filled('lat') && $request->filled('lng')) {
                // Scenario 1: Location provided -> show all stores nearest to customer
                $query->orderBy('distance_km');
            } else {
                // Scenario 1: No location set -> show premium stores first, then highest ratings & reviews count
                $query->orderByDesc('is_featured')
                    ->orderByDesc(
                        StoreSubscription::select('store_subscriptions.id')
                            ->join('subscription_plans', 'subscription_plans.id', '=', 'store_subscriptions.plan_id')
                            ->whereColumn('store_subscriptions.store_id', 'stores.id')
                            ->where('store_subscriptions.status', 'active')
                            ->where('subscription_plans.slug', 'premium')
                            ->limit(1)
                    )
                    ->orderByDesc('reviews_avg_rating')
                    ->orderByDesc('reviews_count')
                    ->orderByDesc('created_at');
            }
        } else {
            match ($sortBy) {
                'name_asc' => $query->orderBy('name'),
                'rating' => $query->orderByDesc('reviews_avg_rating')->orderByDesc('reviews_count'),
                'distance' => $query->orderBy('distance_km'),
                // Shops with no price at all go last either way.
                'price_asc' => (function () use ($query, $priceBound) {
                    [$items, $services, $b] = $priceBound('MIN');
                    $query->orderByRaw("COALESCE(LEAST(COALESCE($items, 999999999), COALESCE($services, 999999999)), 999999999) ASC", $b);
                })(),
                'price_desc' => (function () use ($query, $priceBound) {
                    [$items, $services, $b] = $priceBound('MAX');
                    $query->orderByRaw("GREATEST(COALESCE($items, -1), COALESCE($services, -1)) DESC", $b);
                })(),
                'newest' => $query->orderByDesc('created_at'),
                default => $query->orderByDesc('is_featured')->orderByDesc('created_at'),
            };
        }

        $stores = $query->with([
            // With a district filter, that district's branch comes first — the card shows branches[0].
            'branches' => function ($q) use ($request) {
                $q->live();
                if ($request->filled('district')) {
                    $q->orderByRaw('CASE WHEN district = ? THEN 0 ELSE 1 END', [$request->string('district')->toString()]);
                }
            },
            'services' => function ($q) use ($inPrice) {
                $q->where('is_active', true)->withCount('reviews')->withAvg('reviews', 'rating');
                $inPrice($q, 'base_price');
            },
            'owner:id,name',
            'catalogItems' => function ($cq) use ($request, $inPrice) {
                $cq->listed()->with('images');
                $inPrice($cq, 'price');
                if ($request->filled('q')) {
                    $search = strtolower((string) $request->string('q'));
                    $cq->orderByRaw('CASE WHEN LOWER(name) LIKE ? OR LOWER(garment_type) LIKE ? OR LOWER(material) LIKE ? OR LOWER(description) LIKE ? OR EXISTS (SELECT 1 FROM services WHERE services.id = catalog_items.service_id AND (LOWER(services.name) LIKE ? OR LOWER(services.category) LIKE ? OR LOWER(services.service_type) LIKE ?)) THEN 0 ELSE 1 END', [
                        '%'.$search.'%',
                        '%'.$search.'%',
                        '%'.$search.'%',
                        '%'.$search.'%',
                        '%'.$search.'%',
                        '%'.$search.'%',
                        '%'.$search.'%',
                    ]);
                }
                $cq->latest()->take(10);
            },
        ])->paginate($request->input('per_page', 20));

        // withAvg() returns reviews_avg_rating as whatever PDO hands back for
        // AVG() (a numeric string, not a float) since it's not a real column
        // Eloquent can cast — publicProfile()/show() round() it per-model for
        // the same reason; do the same here across the paginated collection.
        $stores->getCollection()->transform(function ($store) {
            $store->reviews_avg_rating = $store->reviews_avg_rating !== null
                ? round((float) $store->reviews_avg_rating, 1)
                : null;

            if ($store->relationLoaded('services')) {
                $store->services->each(function ($svc) {
                    $svc->reviews_avg_rating = $svc->reviews_avg_rating !== null
                        ? round((float) $svc->reviews_avg_rating, 1)
                        : null;
                });
            }

            return $store;
        });

        return response()->json([
            'success' => true,
            'data' => $stores->items(),
            'meta' => [
                'current_page' => $stores->currentPage(),
                'last_page' => $stores->lastPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
            ],
        ]);
    }

    public function publicProfile(Store $store): JsonResponse
    {
        // This route intentionally carries no `auth:sanctum` middleware (it's
        // the public storefront, guests must be able to load it) but the
        // owner still needs to reach their own hidden store's profile to edit
        // it — resolving the guard manually here (instead of relying on
        // route middleware to populate $request->user()) lets a Bearer token
        // still identify the owner without forcing auth on everyone else.
        $viewer = auth('sanctum')->user();
        // Pending/rejected stores are also private — only admin-approved
        // stores may appear publicly, same rule publicIndex() applies.
        $isPubliclyVisible = ! $store->is_hidden && $store->status === 'approved';
        if (! $isPubliclyVisible && (! $viewer || $viewer->id !== $store->owner_id)) {
            return response()->json(['success' => false, 'message' => 'Store not found'], 404);
        }

        $store->loadCount('reviews');
        $store->loadAvg('reviews', 'rating');
        $store->reviews_avg_rating = round($store->reviews_avg_rating, 1);

        if ($viewer) {
            $store->my_review = StoreReview::where('store_id', $store->id)
                ->where('user_id', $viewer->id)
                ->first(['id', 'rating', 'comment']);
        } else {
            $store->my_review = null;
        }

        // Column-restricted eager load: this is the public, unauthenticated
        // storefront — the frontend only ever reads id/name/email/profile_picture
        // off `owner` (see StoreProfile.owner in store/[store_id]/page.tsx), but an
        // unrestricted load() was shipping the owner's full User record —
        // phone, exact last_seen_at, bio/education/skills, deleted_at — to any
        // anonymous visitor. Every other public endpoint here (reviews, posts)
        // already minimizes user data the same way; this one was the outlier.
        // The owner viewing their own storefront should still see a branch
        // they just added that hasn't gone through verification yet (or one
        // they've since deactivated) — an anonymous/other visitor only ever
        // sees active branches, same as before.
        $isOwnerViewer = $viewer && $viewer->id === $store->owner_id;
        $store->load(['branches' => function ($query) use ($isOwnerViewer) {
            if (! $isOwnerViewer) {
                $query->live();
            }
        }, 'owner:id,name,profile_picture']);

        // The owner's login email is the account identifier — never part of a public storefront
        // (the shop's own business email/phone are already on the store itself). Admin-side
        // bookkeeping (who approved it, rejection / takedown reasons) is for the owner and admin only.
        if (! $isOwnerViewer) {
            $store->makeHidden(['approved_by', 'rejection_reason', 'admin_hidden_reason', 'deleted_at']);
        }

        return response()->json([
            'success' => true,
            'data' => $store,
        ]);
    }

    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $validated = $request->validated();

        // An admin takedown can only be lifted by an admin — without this the
        // owner could flip their own visibility toggle back on. Held silently
        // (not rejected) so the rest of a settings save still goes through.
        if ($store->admin_hidden_at) {
            $validated['is_hidden'] = true;
        }

        $store->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Store updated successfully.',
            'data' => $store,
        ]);
    }

    public function destroy(Request $request, Store $store): JsonResponse
    {
        if ($request->user()->id !== $store->owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this store.',
            ], 403);
        }

        $store->delete();

        return response()->json([
            'success' => true,
            'message' => 'Store deleted successfully.',
        ]);
    }
}
