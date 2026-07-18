<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
Broadcast::channel('system.status', function ($user) {
    return $user !== null; // any authenticated user; tighten to admin role if needed
});
