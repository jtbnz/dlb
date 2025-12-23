<?php

namespace App\Controllers;

use App\Models\Brigade;

class HomeController
{
    public function index(): void
    {
        $brigades = Brigade::all();
        echo view('home', ['brigades' => $brigades]);
    }
}
