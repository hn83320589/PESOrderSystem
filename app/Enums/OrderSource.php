<?php

namespace App\Enums;

enum OrderSource: string
{
    case Admin = 'admin';
    case Customer = 'customer';
}
