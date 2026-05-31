<?php

namespace App\Repository;

use App\Models\Appeal;

class AppealRepository
{
    public function pending()
    {
        return Appeal::with('user')->where('status', 'pending')->latest()->get();
    }

    public function create(array $payload): Appeal
    {
        return Appeal::create($payload);
    }
}
