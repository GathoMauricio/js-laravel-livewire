<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Represents an inventory item and its available stock. */
#[Fillable(['code', 'name', 'stock'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /** Departments currently holding an allocation of this product. */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)
            ->withPivot('quantity')
            ->withTimestamps();
    }
}
