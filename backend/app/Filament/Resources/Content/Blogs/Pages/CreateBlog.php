<?php

namespace App\Filament\Resources\Content\Blogs\Pages;

use App\Filament\Resources\Content\Blogs\BlogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlog extends CreateRecord
{
    protected static string $resource = BlogResource::class;
}
