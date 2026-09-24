<?php

namespace App\Schema;

use App\Schema\FieldTypes\FieldTypeRegistry;
use Illuminate\Support\ServiceProvider;

class SchemaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A singleton, so a module's provider can add its field types in
        // boot() and every later resolve sees them.
        $this->app->singleton(FieldTypeRegistry::class, fn () => FieldTypeRegistry::withCoreTypes());
    }
}
