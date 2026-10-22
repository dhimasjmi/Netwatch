<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\UserRole;
use App\Exceptions\UserManagementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ResetUserPasswordRequest;
use App\Http\Requests\Web\StoreUserRequest;
use App\Http\Requests\Web\UpdateUserRequest;
use App\Models\User;
use App\Services\UserManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class UserController extends Controller
{
    public function __construct(private readonly UserManager $users)
    {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $role = UserRole::tryFrom((string) $request->query('role', ''));

        $users = User::query()
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
            })
            ->when($role !== null, fn ($query) => $query->where('role', $role->value))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('users.index', ['users' => $users, 'search' => $search, 'role' => $role, 'roles' => UserRole::cases()]);
    }

    public function create(): View
    {
        return view('users.create', ['roles' => UserRole::cases()]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->users->create($request->user(), $request->validated());

        return redirect()->route('users.index')->with('status', 'Pengguna dibuat.');
    }

    public function edit(User $user): View
    {
        return view('users.edit', ['user' => $user, 'roles' => UserRole::cases()]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        try {
            $this->users->update($request->user(), $user, $request->validated());
        } catch (UserManagementException $e) {
            return back()->withInput()->withErrors(['role' => $e->getMessage()]);
        }

        return redirect()->route('users.index')->with('status', 'Pengguna diperbarui.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        try {
            $this->users->setActive($request->user(), $user, ! $user->is_active);
        } catch (UserManagementException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        }

        return back()->with('status', $user->is_active ? 'Akun diaktifkan.' : 'Akun dinonaktifkan dan sesinya dicabut.');
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $this->users->resetPassword($request->user(), $user, $request->validated('password'));

        return redirect()->route('users.index')->with('status', 'Kata sandi diganti dan semua sesi pengguna dicabut.');
    }
}
