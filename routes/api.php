<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    // Public endpoints: no tenant exists yet for these requests.
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register')->name('register');
    Route::post('auth/tokens', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::post('invitations/accept', [AuthController::class, 'acceptInvitation'])->middleware('throttle:login')->name('invitations.accept');

    // Tenant endpoints: 'tenant' sets the organization from the authenticated user.
    Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {
        Route::delete('auth/tokens/current', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');

        Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
    });
});
