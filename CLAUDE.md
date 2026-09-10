# BankerTrader — Coding Standards & Architecture Guide

This file is the **source of truth** for how code is written in this Laravel
application. It merges clean-architecture / SOLID best practices with
production conventions already proven in this team's other apps (see the
`ai-powered-form-builder` reference). Follow it for every task.

## Principles (non-negotiable)

1. **Think before coding** — state ambiguity, present options, ask don't guess.
2. **Simplicity first** — build only what was asked, no speculative abstractions.
3. **Surgical changes** — every line traces to the request; don't "improve"
   adjacent code.
4. **Goal-driven execution** — define success criteria, loop until met.

## SOLID, applied

- **S — Single Responsibility:** each class has exactly one reason to change.
  Controllers never contain business logic.
- **O — Open/Closed:** extend behavior via new classes, don't modify existing ones.
- **L — Liskov Substitution:** implementations must be drop-in for their interfaces.
- **I — Interface Segregation:** many focused interfaces > one general interface.
- **D — Dependency Inversion:** depend on abstractions (interfaces), not concretions.

## Folder structure

```
app/
├── Actions/            # Small single-purpose service classes (Fortify etc.)
├── Concerns/           # Reusable model traits/concerns
├── Console/Commands/   # Custom artisan commands
├── Contracts/          # Interfaces
│   └── Repositories/   # Repository interfaces (e.g. PostRepositoryInterface)
├── DTOs/               # Simple data transfer objects
├── Enums/              # Typed enums: Role, PostStatus, GenerationStatus ...
├── Events/             # Domain events: PostPublished, CommentCreated ...
├── Exceptions/         # Custom exceptions (e.g. PostCannotBePublishedException)
├── Http/
│   ├── Controllers/    # Thin controllers — only HTTP concerns
│   ├── Middleware/
│   ├── Requests/       # FormRequest validation classes
│   └── Resources/      # API resources for response formatting
├── Jobs/               # Queue jobs (heavy/async work)
├── Listeners/          # React to events: notifications, cache, logs
├── Models/             # Eloquent models + relationships + scopes
├── Notifications/      # Mailable/notification classes
├── Policies/           # Authorization policies
├── Providers/          # Service providers + interface bindings
├── Repositories/       # Data access layer
│   └── Eloquent/       # Concrete implementations
├── Services/           # Business logic layer
└── Traits/             # Shared traits

database/
├── factories/
├── migrations/
└── seeders/

resources/views/        # Blade views (or Inertia React pages)
routes/
├── web.php             # Browser routes
└── api.php             # JSON API routes

tests/
├── Feature/
└── Unit/
```

## Layering rule (request → data)

```
Request → Controller → Service → Repository → Model
              ↑            ↑          ↑
          validates   business     SQL/query
                       logic        only
```

- **Controllers** are thin: validate, authorize, call **one** service, return a
  response. No business logic, no direct queries.
- **Form Requests** handle validation (and authorization checks).
- **Services** own business rules; dependencies are constructor-injected.
- **Repositories** do all DB/query work; models never leak into controllers.
- **Models** stay as data containers + relationships + scopes.
- **Policies** centralize authorization.
- **Resources** format API responses consistently.

## Base repository

Extend a `BaseRepository` (in `app/Repositories/BaseRepository.php`) for
generic CRUD, then add scoped queries in the concrete class.

```php
// app/Repositories/BaseRepository.php
abstract class BaseRepository implements BaseRepositoryInterface
{
    protected Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Model
    {
        return $this->model->newQuery()->find($id);
    }

    public function create(array $data): Model
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(int $id, array $data): ?Model
    {
        $model = $this->find($id);
        if (! $model) {
            return null;
        }
        $model->fill($data)->save();

        return $model->refresh();
    }

    public function delete(int $id): bool
    {
        return (bool) $this->findOrFail($id)->delete();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()->paginate($perPage);
    }
}
```

Concrete repository (implements its interface, extends `BaseRepository`):

```php
// app/Repositories/Eloquent/EloquentPostRepository.php
class EloquentPostRepository extends BaseRepository implements PostRepositoryInterface
{
    public function findBySlug(string $slug): ?Post
    {
        return $this->model->newQuery()
            ->with(['author', 'categories'])
            ->where('slug', $slug)
            ->first();
    }

    public function getPublished(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->with(['author', 'categories'])
            ->published()
            ->latest('published_at')
            ->paginate($perPage);
    }
}
```

## Service layer

Services own the business logic and dispatch events. Dependencies are
constructor-injected (Laravel auto-resolves them). Wrap multi-step operations
in transactions and dispatch events on success.

```php
// app/Services/PostService.php
class PostService
{
    public function __construct(
        private readonly PostRepositoryInterface $posts,
    ) {}

    public function createPost(array $data, int $authorId): Post
    {
        return DB::transaction(function () use ($data, $authorId) {
            $post = $this->posts->create([
                'author_id' => $authorId,
                'slug'      => $this->generateUniqueSlug($data['title']),
                ...$data,
            ]);

            if (! empty($data['categories'])) {
                $post->categories()->attach($data['categories']);
            }

            PostCreated::dispatch($post);

            return $post->fresh(['author', 'categories']);
        });
    }
}
```

## Thin controller

```php
// app/Http/Controllers/PostController.php
class PostController extends Controller
{
    public function __construct(
        private readonly PostService $posts,
    ) {}

    public function store(StorePostRequest $request): JsonResponse
    {
        $post = $this->posts->createPost($request->validated(), $request->user()->id);

        return (new PostResource($post))->response()->setStatusCode(201);
    }

    public function destroy(Post $post): JsonResponse
    {
        $this->authorize('delete', $post);
        $this->posts->deletePost($post);

        return response()->json(['message' => 'Deleted successfully'], 204);
    }
}
```

## Validation — FormRequest

```php
// app/Http/Requests/StorePostRequest.php
class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Post::class);
    }

    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'excerpt'     => ['nullable', 'string', 'max:500'],
            'content'     => ['required', 'string'],
            'status'      => ['nullable', 'in:draft,review,published,archived'],
            'is_featured' => ['nullable', 'boolean'],
            'categories'  => ['nullable', 'array'],
            'categories.*' => ['exists:categories,id'],
        ];
    }
}
```

## Authorization — Policy

Register policies and only authorize per-action. Keep policies free of
business logic — they only answer "can this user do X?"

```php
// app/Policies/PostPolicy.php
class PostPolicy
{
    public function view(User $user, Post $post): bool
    {
        return $user->id === $post->author_id || $post->isPublished();
    }

    public function update(User $user, Post $post): bool
    {
        return $user->id === $post->author_id || $user->hasRole('admin');
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->id === $post->author_id || $user->hasRole('admin');
    }
}
```

## Service-provider bindings

Bind interfaces to their concrete implementations in a provider. In this app
use `app/Providers/AppServiceProvider.php` (or a dedicated provider).

```php
// app/Providers/AppServiceProvider.php
public function register(): void
{
    $this->app->bind(PostRepositoryInterface::class, EloquentPostRepository::class);
    $this->app->bind(CategoryRepositoryInterface::class, EloquentCategoryRepository::class);
}

public function boot(): void
{
    $this->app['events']->listen([
        PostCreated::class,
        PostPublished::class,
    ], LogActivity::class);
}
```

## API authentication (Passport)

Passport-backed API auth lives under `app/Http/Controllers/Api/AuthController.php`
with a service/repository split. Endpoints: `POST api/login`, `refresh`, `logout`,
`forgot-password`, `reset-password`, `change-password`, and `GET api/me`.

Key conventions:
- **`api` guard** uses `driver => 'passport'` (`config/auth.php`); User model uses
  `HasApiTokens` and implements `Laravel\Passport\Contracts\OAuthenticatable`.
- **OAuth client** is a password-grant client referenced via
  `config('passport.client_id')` / `config('passport.client_secret')` (from `.env`).
  Secrets are stored **hashed** in the `oauth_clients` table; plaintext lives in `.env`
  only. `Passport::enablePasswordGrant()` is called in `AppServiceProvider::boot()`.
- **Tokens are issued in-process** (NOT a nested HTTP call to `/oauth/token`, which
  deadlocks under the single-worker `php -S` dev server). `AuthService` builds a
  `SymfonyRequest`, converts it via `PsrHttpFactory`, and calls
  `AuthorizationServer::respondToAccessTokenRequest()` directly.
- Response shape comes from the `App\Traits\ResponseStructure` trait
  (`successResponse` / `errorResponse` / `paginated`), mirroring the reference
  project. Controllers read results via `$result->getData(true)`.
- Add new authenticated API routes behind the `auth:api` middleware in `routes/api.php`.

## API resources

Resources format responses consistently. Use `whenLoaded` for relationships
and `when()` for conditional fields.

```php
// app/Http/Resources/PostResource.php
class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'slug'         => $this->slug,
            'status'       => $this->status,
            'is_featured'  => $this->is_featured,
            'published_at' => $this->published_at?->toIso8601String(),
            'author'       => new UserResource($this->whenLoaded('author')),
            'categories'   => CategoryResource::collection($this->whenLoaded('categories')),
        ];
    }
}
```

## Routes

Keep routes RESTful and named. Separate public vs authenticated groups.

```php
// routes/api.php
Route::get('posts', [PostController::class, 'index']);
Route::get('posts/{slug}', [PostController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('posts', [PostController::class, 'store']);
    Route::put('posts/{post}', [PostController::class, 'update']);
    Route::delete('posts/{post}', [PostController::class, 'destroy']);
    Route::post('posts/{post}/publish', [PostController::class, 'publish']);
});
```

## Common mistakes to avoid

- ❌ Putting business logic in controllers — delegate to services.
- ❌ Querying the DB directly in controllers — use repositories.
- ❌ Skipping validation — always use FormRequests, never `$request->all()`.
- ❌ Skipping authorization — always call `$this->authorize(...)` or a policy.
- ❌ Returning Eloquent models directly from API controllers — use Resources.
- ❌ Large un-scoped repository classes — keep them focused.

## Quick-start checklist for a new feature

1. Create the **model** + migration.
2. Create `app/Contracts/Repositories/{Name}RepositoryInterface.php` and
   `app/Repositories/Eloquent/Eloquent{Name}Repository.php` extending `BaseRepository`.
3. Register the interface binding in `AppServiceProvider::register()`.
4. Create `app/Services/{Name}Service.php` with the business logic.
5. Create the **FormRequest** for validation.
6. Create the **controller** — thin, delegates to the service.
7. Add the **route** in `routes/web.php` or `routes/api.php`.
8. Add a **policy** if the feature is user-scoped; register it.
9. Create **Resources** for any JSON output.
10. Dispatch **events** from the service; register listeners in `boot()`.
11. Write a **feature test** in `tests/Feature/`.

## Testing

- Feature tests cover full HTTP flows (`RefreshDatabase`).
- Unit tests cover services (mock the repository interface).
- Keep tests focused on behavior, not implementation.

```
tests/Feature/PostControllerTest.php
tests/Unit/PostServiceTest.php
```

---

Whenever you start a task, re-read this file, follow the layering rule, and
apply the quick-start checklist. This keeps the codebase maintainable,
testable, and scalable.
