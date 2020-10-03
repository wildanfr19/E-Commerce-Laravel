<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class Category extends Model
{

	protected $fillable = ['name', 'parent_id', 'slug'];
	public function parent()
	{
		return $this->belongsTo(Category::class);
	}

	public function scopeGetParent($query)
	{
		return $query->whereNull('parent_id');
	}

    //MUTATOR
	public function setSlugAttribute($value)
	{
		$this->attributes['slug'] = Str::slug($value);
	}

    //ACCESSOR
	public function getNameAttribute($value)
	{
		return ucfirst($value);
	}

	public function child()
	{
        //MENGGUNAKAN RELASI ONE TO MANY DENGAN FOREIGN KEY parent_id
		return $this->hasMany(Category::class, 'parent_id');
	}

	public function product()
	{
		return $this->hasMany(Product::class);
	}

}
